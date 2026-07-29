<?php

namespace App\Services\DevEngine;

use App\Models\ChangeSet;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\AiTextGenerator;
use RuntimeException;

/**
 * The reasoning loop for a feature request: retrieve relevant files from the
 * Project Brain, produce a structured plan, and — once the plan is approved —
 * generate real file content one file at a time in dependency order. Nothing
 * in this service touches disk; it only ever reads/writes `project_files`,
 * `change_sets`, and `change_set_files`. Applying a change set to the user's
 * actual project folder is a separate, later step (ChangeSetService).
 */
class FeatureAgentService
{
    private const VALID_ACTIONS = ['create', 'modify', 'delete'];

    // Rough generation order so a controller prompt already has the model it
    // depends on, a route already has the controller, etc. Not a real
    // dependency-graph topological sort — a pragmatic convention-based
    // ordering good enough for the common create/modify-a-feature case.
    private const PATH_PRIORITY = [
        'migrations' => 1,
        'Models' => 2,
        'Services' => 3,
        'Controllers' => 4,
        'routes' => 5,
        'lib' => 6,
        'components' => 7,
        'app' => 8,
    ];

    public function __construct(
        private AiTextGenerator $ai,
        private DocumentationService $documentation,
        private ContextEngineService $contextEngine,
    ) {}

    /**
     * The single place a feature/refactor request gets created — used both
     * by the standalone Feature Builder entry point and by Maya's chat when
     * it classifies a message as `refactor`/`feature_request`, so there is
     * exactly one code path that creates a FeatureRequest and plans it.
     */
    public function createAndPlan(Project $project, User $user, string $prompt, ?string $activeFile = null): FeatureRequest
    {
        $featureRequest = $project->featureRequests()->create([
            'user_id' => $user->id,
            'prompt' => $prompt,
            'status' => 'planning',
        ]);

        $this->generatePlan($project, $featureRequest, $activeFile);

        return $featureRequest->load('changeSet.files');
    }

    /**
     * $activeFile force-includes that path in context even when keyword
     * matching alone wouldn't reliably find it (short/generic filenames) —
     * used by test-generation and fix-suggestion prompts, which always have
     * one specific real target file.
     */
    public function generatePlan(Project $project, FeatureRequest $featureRequest, ?string $activeFile = null): ChangeSet
    {
        $context = $this->contextEngine->relatedFiles($project, $featureRequest->prompt, $activeFile);

        $contextBlock = $context->map(function (ProjectFile $f) {
            $symbols = $f->symbols ? ' ['.implode(', ', $f->symbols).']' : '';

            return sprintf('- %s (%s): %s%s', $f->path, $f->language ?? 'unknown', $f->summary ?? 'no summary', $symbols);
        })->implode("\n");

        $systemPrompt = 'You are a senior software engineer planning a code change for an existing project. '
            .'Given a short index of relevant existing files and a feature request, respond with ONLY a JSON object: '
            .'{"summary": "one sentence plan overview", "files": [{"path": "...", "action": "create|modify|delete", "reason": "why this file needs this change"}]}. '
            .'Prefer modifying existing files over creating new ones when reasonable. No prose outside the JSON.';

        $userPrompt = sprintf(
            "Existing project files:\n%s\n\nFeature request: %s",
            $contextBlock ?: '(no files indexed yet)',
            $featureRequest->prompt,
        );

        $raw = $this->ai->generate($systemPrompt, $userPrompt, 2000);
        $parsed = $this->parseJson($raw);

        if (! $parsed || ! isset($parsed['files']) || ! is_array($parsed['files'])) {
            throw new RuntimeException('AI plan response was unparseable.');
        }

        $parsed['files'] = $this->withReadmeEntry($project, $parsed['files']);

        $changeSet = $featureRequest->changeSet()->create([
            'status' => 'draft',
            'summary' => is_string($parsed['summary'] ?? null) ? $parsed['summary'] : null,
        ]);

        foreach ($parsed['files'] as $planItem) {
            $path = is_array($planItem) ? ($planItem['path'] ?? null) : null;
            if (! $path) {
                continue;
            }

            $action = $planItem['action'] ?? 'modify';
            $existing = ProjectFile::where('project_id', $project->id)->where('path', $path)->first();

            $changeSet->files()->create([
                'project_file_id' => $existing?->id,
                'path' => $path,
                'action' => in_array($action, self::VALID_ACTIONS, true) ? $action : 'modify',
                'reason' => is_string($planItem['reason'] ?? null) ? $planItem['reason'] : null,
            ]);
        }

        $featureRequest->update(['status' => 'awaiting_plan_approval']);

        return $changeSet->load('files');
    }

    /**
     * @param  array<int, string>  $approvedPaths
     */
    public function approvePlan(ChangeSet $changeSet, array $approvedPaths): array
    {
        $changeSet->files()->whereIn('path', $approvedPaths)->update(['plan_approved' => true]);
        $changeSet->files()->whereNotIn('path', $approvedPaths)->delete();

        $changeSet->featureRequest->update(['status' => 'generating']);

        $needsContent = $changeSet->files()
            ->where('plan_approved', true)
            ->where('action', 'modify')
            ->pluck('path')
            ->all();

        return ['needsContent' => $needsContent];
    }

    /**
     * @param  array<string, string>  $currentContentByPath  path => current on-disk content, for "modify" actions only
     */
    public function generateContent(ChangeSet $changeSet, array $currentContentByPath): ChangeSet
    {
        $prompt = $changeSet->featureRequest->prompt;
        $files = $changeSet->files()->where('plan_approved', true)->get()
            ->sortBy(fn ($f) => $this->pathPriority($f->path))
            ->values();

        $generatedSoFar = [];

        foreach ($files as $file) {
            if ($file->action === 'delete') {
                continue;
            }

            $priorBlock = collect($generatedSoFar)
                ->map(fn ($content, $path) => sprintf("### %s\n```\n%s\n```", $path, mb_substr($content, 0, 1500)))
                ->implode("\n\n");

            $currentContent = $file->action === 'modify' ? ($currentContentByPath[$file->path] ?? '') : '';

            if (DocumentationService::isReadmePath($file->path)) {
                $content = $this->documentation->generateReadmePatch($currentContent, $prompt);
                $file->update(['new_content' => $content]);
                $generatedSoFar[$file->path] = $content;

                continue;
            }

            $systemPrompt = 'You are a senior software engineer implementing one file of an approved feature plan. '
                .'Respond with ONLY the complete raw content of the file — no explanation, no markdown code fences, no commentary.';

            $userPrompt = sprintf(
                "Feature request: %s\n\nWhy this file: %s\n\nOther files already generated in this change set (for consistency):\n%s\n\n%s %s%s",
                $prompt,
                $file->reason ?: '(no reason given)',
                $priorBlock ?: '(none yet)',
                $file->action === 'modify' ? 'Current content of' : 'New file',
                $file->path,
                $currentContent ? ":\n```\n{$currentContent}\n```" : '',
            );

            $raw = $this->ai->generate($systemPrompt, $userPrompt, 3000);
            $content = $this->stripCodeFences($raw);

            $file->update(['new_content' => $content]);
            $generatedSoFar[$file->path] = $content;
        }

        $changeSet->featureRequest->update(['status' => 'awaiting_diff_approval']);

        return $changeSet->load('files');
    }

    /**
     * Documentation updates aren't optional or AI-decided — if the project
     * has an indexed README, every feature plan gets a README.md entry added
     * automatically so it flows through the same plan/diff-approval screens
     * as code (the user can still uncheck it during plan review).
     *
     * @param  array<int, mixed>  $planFiles
     * @return array<int, mixed>
     */
    private function withReadmeEntry(Project $project, array $planFiles): array
    {
        $alreadyPlanned = collect($planFiles)->contains(
            fn ($f) => is_array($f) && DocumentationService::isReadmePath($f['path'] ?? '')
        );

        if ($alreadyPlanned) {
            return $planFiles;
        }

        $readmeFile = ProjectFile::where('project_id', $project->id)
            ->whereIn('path', ['README.md', 'Readme.md', 'readme.md'])
            ->first();

        if (! $readmeFile) {
            return $planFiles;
        }

        $planFiles[] = [
            'path' => $readmeFile->path,
            'action' => 'modify',
            'reason' => 'Document the new feature.',
        ];

        return $planFiles;
    }

    private function pathPriority(string $path): int
    {
        foreach (self::PATH_PRIORITY as $needle => $priority) {
            if (str_contains($path, $needle)) {
                return $priority;
            }
        }

        return 99;
    }

    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function stripCodeFences(string $text): string
    {
        // Strip only a literal wrapping ```lang fence if the model added one
        // despite being told not to — preserves the file's own trailing
        // newline rather than trimming all surrounding whitespace away.
        $text = preg_replace('/^[ \t]*```[A-Za-z0-9]*\r?\n/', '', $text, 1);
        $text = preg_replace('/\r?\n[ \t]*```[ \t]*$/', '', $text, 1);

        return $text;
    }
}
