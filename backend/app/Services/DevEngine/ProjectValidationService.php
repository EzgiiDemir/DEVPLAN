<?php

namespace App\Services\DevEngine;

use App\Models\Module;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Services\AiTextGenerator;

/**
 * The AI half of the pre-Studio "Project Review" gate: reads across all 12
 * modules and flags genuine contradictions between them — a requirement the
 * chosen tech stack can't actually support, an API endpoint assuming a data
 * model another section rules out, a sprint plan missing something the
 * requirements module calls mandatory. Deliberately informational, never
 * blocking — same posture as ConfidenceScorer/StackConformanceChecker
 * elsewhere in DevEngine: surfaced to the user, never enforced server-side,
 * since the AI's read can itself be wrong.
 */
class ProjectValidationService
{
    public function __construct(
        private ContextEngineService $context,
        private AiTextGenerator $ai,
    ) {}

    /**
     * @return array{issues: array<int, array{severity: string, message: string}>}
     */
    public function validate(Project $project): array
    {
        $systemPrompt = <<<TEXT
        You are reviewing a software project's planning documents for genuine
        internal contradictions before development starts — not a style or
        completeness review. Only report specific, concrete conflicts between
        two or more sections below (e.g. a requirement the chosen tech stack
        cannot support, an API endpoint implying a data model another section
        rules out, a sprint plan that omits something explicitly required).

        Do not invent generic advice, do not nitpick wording, and do not
        report a missing section as a contradiction — only real conflicts
        between things that are both actually present. If you find none,
        return an empty issues array; do not fabricate one to have something
        to say.

        Respond with strict JSON only, no prose, no markdown fences:
        {"issues": [{"severity": "high"|"medium"|"low", "message": "..."}]}
        TEXT;

        $userPrompt = implode("\n\n---\n\n", array_filter([
            $this->context->projectSpec($project),
            $this->requirementsBlock($project),
            $this->context->apiEndpoints($project),
            $this->context->sprintStatus($project),
        ]));

        $raw = $this->ai->generate($systemPrompt, $userPrompt, 1200);
        $parsed = $this->parseJson($raw);

        $issues = is_array($parsed['issues'] ?? null) ? $parsed['issues'] : [];

        return [
            'issues' => array_values(array_filter($issues, fn ($issue) => is_array($issue) && ! empty($issue['message']))),
        ];
    }

    private function requirementsBlock(Project $project): ?string
    {
        $module = Module::where('project_id', $project->id)->where('module_type', 'requirements')->first();
        $requirements = $module
            ? ModuleItem::where('module_id', $module->id)->where('item_type', 'requirement')->get()
            : collect();

        if ($requirements->isEmpty()) {
            return null;
        }

        $list = $requirements->map(fn ($r) => sprintf(
            '- %s%s',
            $r->content['feature'] ?? '(unnamed)',
            ! empty($r->content['story']) ? ": {$r->content['story']}" : '',
        ))->implode("\n");

        return "Requirements:\n{$list}";
    }

    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }
}
