<?php

namespace App\Services\DevEngine;

use App\Models\Module;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Services\AiTextGenerator;

/**
 * Mostly deterministic — file-existence checks and parsing the project's own
 * already-generated .env.example template. The one AI call at the end turns
 * those facts into a short plain-English summary; it's never asked to
 * invent facts about the project, only to phrase ones already computed.
 */
class DeploymentAnalyzerService
{
    private const CONFIG_FILE_FOR_PLATFORM = [
        'vercel' => 'vercel.json',
        'railway' => 'railway.json',
        'aws_amplify' => 'amplify.yml',
        'docker' => 'Dockerfile',
        // Render's workflow is "connect a repo once in Render's own
        // dashboard, then git push" — no local config file for DevPlan to
        // check for.
        'render' => null,
    ];

    public function __construct(private AiTextGenerator $ai) {}

    /**
     * @param  array{platform: string, hasVercelJson?: bool, hasRailwayJson?: bool, hasAmplifyYml?: bool, hasDockerfile?: bool}  $signals
     */
    public function analyze(Project $project, array $signals): array
    {
        $platform = $signals['platform'] ?? '';
        $missingConfigFiles = $this->missingConfigFor($platform, $signals);

        $envExample = $this->envExampleTemplate($project);
        $requiredEnvVars = $this->parseEnvVarNames($envExample);

        $warnings = [];
        if ($envExample === null) {
            $warnings[] = 'No .env.example found — complete the Environment module first for an accurate required-variables list.';
        }

        $result = [
            'platform_ready' => empty($missingConfigFiles),
            'missing_config_files' => $missingConfigFiles,
            'required_env_vars' => $requiredEnvVars,
            'warnings' => $warnings,
        ];

        $result['summary'] = $this->generateSummary($platform, $result);

        return $result;
    }

    private function missingConfigFor(string $platform, array $signals): array
    {
        $configFile = self::CONFIG_FILE_FOR_PLATFORM[$platform] ?? null;
        if ($configFile === null) {
            return [];
        }

        $hasFile = match ($platform) {
            'vercel' => ! empty($signals['hasVercelJson']),
            'railway' => ! empty($signals['hasRailwayJson']),
            'aws_amplify' => ! empty($signals['hasAmplifyYml']),
            'docker' => ! empty($signals['hasDockerfile']),
            default => true,
        };

        return $hasFile ? [] : [$configFile];
    }

    private function envExampleTemplate(Project $project): ?string
    {
        $envModule = Module::where('project_id', $project->id)->where('module_type', 'environment')->first();
        if (! $envModule) {
            return null;
        }

        $item = ModuleItem::where('module_id', $envModule->id)->where('item_type', 'env_files')->first();

        return $item?->content['envExample'] ?? null;
    }

    private function parseEnvVarNames(?string $envExample): array
    {
        if (! $envExample) {
            return [];
        }

        preg_match_all('/^([A-Z_][A-Z0-9_]*)\s*=/m', $envExample, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function generateSummary(string $platform, array $facts): string
    {
        $systemPrompt = 'You are a deployment readiness assistant. Given the facts below about a project '
            .'preparing to deploy, write a short (2-3 sentence) plain-English summary of its deployment readiness. '
            .'Only describe the facts given — never invent missing details or assume anything not stated.';

        $userPrompt = sprintf(
            "Platform: %s\nMissing config files: %s\nRequired environment variables: %s\nWarnings: %s",
            $platform,
            $facts['missing_config_files'] ? implode(', ', $facts['missing_config_files']) : 'none',
            $facts['required_env_vars'] ? implode(', ', $facts['required_env_vars']) : 'none declared',
            $facts['warnings'] ? implode('; ', $facts['warnings']) : 'none',
        );

        return trim($this->ai->generate($systemPrompt, $userPrompt, 300));
    }
}
