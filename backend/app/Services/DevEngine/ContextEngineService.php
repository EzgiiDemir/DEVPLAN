<?php

namespace App\Services\DevEngine;

use App\Models\FileDependency;
use App\Models\Module;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\ProjectCache;
use Illuminate\Support\Collection;

/**
 * The shared retrieval layer for anything that needs to ground an AI call in
 * real project context — used by both FeatureAgentService (code generation)
 * and MayaChatService (conversation). Every method here reads existing data;
 * none of it does any AI work or touches disk.
 */
class ContextEngineService
{
    private const MAX_CONTEXT_FILES = 15;

    private const MAX_ACTIVITY_ITEMS_DEFAULT = 8;

    public function __construct(private ProjectCache $cache) {}

    /**
     * Keyword match against indexed file paths/summaries, expanded by one hop
     * of file_dependencies neighbors — not a vector search, just enough to
     * tell the AI "these N files are probably relevant." $activeFile (the
     * path currently open in the editor, if any) is always included even if
     * it wasn't keyword-matched — the file someone has open is relevant
     * pretty much by definition.
     *
     * @return Collection<int, ProjectFile>
     */
    public function relatedFiles(Project $project, string $query, ?string $activeFile = null): Collection
    {
        $keywords = $this->extractKeywords($query);

        $filesQuery = ProjectFile::where('project_id', $project->id);

        if ($keywords) {
            $filesQuery->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('path', 'like', "%{$keyword}%")
                        ->orWhere('summary', 'like', "%{$keyword}%");
                }
            });
        }

        $matched = $filesQuery->limit(self::MAX_CONTEXT_FILES)->get();

        if ($matched->isEmpty()) {
            $matched = ProjectFile::where('project_id', $project->id)->limit(self::MAX_CONTEXT_FILES)->get();
        }

        $matchedIds = $matched->pluck('id');

        $neighborIds = FileDependency::where('project_id', $project->id)
            ->where(function ($q) use ($matchedIds) {
                $q->whereIn('from_file_id', $matchedIds)->orWhereIn('to_file_id', $matchedIds);
            })
            ->get()
            ->flatMap(fn ($d) => [$d->from_file_id, $d->to_file_id])
            ->unique();

        $allIds = $matchedIds->merge($neighborIds)->unique()->take(self::MAX_CONTEXT_FILES);

        $result = ProjectFile::whereIn('id', $allIds)->get();

        if ($activeFile && ! $result->contains('path', $activeFile)) {
            $activeFileModel = ProjectFile::where('project_id', $project->id)->where('path', $activeFile)->first();
            if ($activeFileModel) {
                $result->push($activeFileModel);
            }
        }

        return $result;
    }

    /**
     * The original 12-module product spec — the parts of it that describe
     * *what* the project is and *why*, not the scanned codebase. This is what
     * lets Maya answer architecture questions grounded in the actual product
     * intent instead of guessing from file names.
     */
    public function projectSpec(Project $project): string
    {
        $sections = [];

        $ideaModule = Module::where('project_id', $project->id)->where('module_type', 'idea')->first();
        $canvas = $ideaModule
            ? ModuleItem::where('module_id', $ideaModule->id)->where('item_type', 'lean_canvas')->first()
            : null;
        if ($canvas) {
            $c = $canvas->content;
            $join = fn (string $key) => implode(', ', $c[$key] ?? []) ?: '-';
            $sections[] = sprintf(
                "Product idea:\n- Problem: %s\n- Solution: %s\n- Customer: %s\n- Revenue: %s\n- Cost: %s\n- Channels: %s",
                $join('problem'), $join('solution'), $join('customer'), $join('revenue'), $join('cost'), $join('channels'),
            );
        }

        $mvpModule = Module::where('project_id', $project->id)->where('module_type', 'mvp_scope')->first();
        $mvpItems = $mvpModule
            ? ModuleItem::where('module_id', $mvpModule->id)->where('item_type', 'mvp_item')->get()
            : collect();
        if ($mvpItems->isNotEmpty()) {
            $list = $mvpItems->map(fn ($i) => sprintf('- [%s] %s', $i->content['column'] ?? '?', $i->content['feature'] ?? ''))->implode("\n");
            $sections[] = "MVP scope:\n{$list}";
        }

        $stackModule = Module::where('project_id', $project->id)->where('module_type', 'tech_stack')->first();
        $stackItem = $stackModule
            ? ModuleItem::where('module_id', $stackModule->id)->where('item_type', 'tech_stack')->first()
            : null;
        if ($stackItem) {
            $s = $stackItem->content;
            $sections[] = sprintf(
                'Tech stack: %s frontend, %s backend, %s database',
                $s['frontend']['selected'] ?? 'unknown', $s['backend']['selected'] ?? 'unknown', $s['database']['selected'] ?? 'unknown',
            );
        }

        return $sections ? implode("\n\n", $sections) : '(no product spec recorded yet)';
    }

    /**
     * The current schema, derived from already-indexed migration file
     * summaries — reflects reality including anything the AI agent itself
     * already added, unlike the original tech-stack planning snapshot.
     */
    public function databaseSchema(Project $project): string
    {
        $migrationFiles = ProjectFile::where('project_id', $project->id)
            ->where('path', 'like', '%/migrations/%')
            ->whereNotNull('summary')
            ->get();

        if ($migrationFiles->isEmpty()) {
            return '(no migrations indexed yet — run a codebase scan)';
        }

        return $migrationFiles->map(fn ($f) => "- {$f->path}: {$f->summary}")->implode("\n");
    }

    /**
     * Primary source is the API Designer module's structured endpoint list
     * (clean {method, path, summary} rows); supplemented with summaries of
     * indexed route files to catch endpoints added later that never got
     * back-filled into that module.
     */
    public function apiEndpoints(Project $project): string
    {
        $lines = [];

        $apiModule = Module::where('project_id', $project->id)->where('module_type', 'api_design')->first();
        $endpointItems = $apiModule
            ? ModuleItem::where('module_id', $apiModule->id)->where('item_type', 'endpoint')->get()
            : collect();

        $seen = [];
        foreach ($endpointItems as $item) {
            $method = strtoupper($item->content['method'] ?? 'GET');
            $path = $item->content['path'] ?? '';
            $key = "{$method} {$path}";
            $seen[$key] = true;
            $lines[] = "- {$key}: ".($item->content['summary'] ?? '');
        }

        $routeFiles = ProjectFile::where('project_id', $project->id)
            ->where('path', 'like', '%routes%')
            ->whereNotNull('summary')
            ->get();
        foreach ($routeFiles as $file) {
            $lines[] = "- {$file->path}: {$file->summary}";
        }

        return $lines ? implode("\n", $lines) : '(no API endpoints recorded yet)';
    }

    /**
     * DevPlan's own record of recent changes — the closest thing to a git
     * history Maya has, since the backend never sees the user's actual git
     * log directly. Only reflects changes made through the approved AI
     * pipeline, not manual commits made outside it.
     */
    public function recentActivity(Project $project, int $limit = self::MAX_ACTIVITY_ITEMS_DEFAULT): string
    {
        $requests = $project->featureRequests()
            ->whereIn('status', ['applied', 'awaiting_diff_approval', 'awaiting_plan_approval'])
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($requests->isEmpty()) {
            return '(no feature history yet)';
        }

        // Now that more than one person can act on a project (Phase 10), who
        // asked for a change is as relevant to Maya's context as what
        // changed — otherwise every request reads as if the same person
        // made it.
        return $requests->map(function ($fr) {
            $fileCount = $fr->changeSet?->files()->count() ?? 0;
            $by = $fr->user?->name ?? 'someone';

            return sprintf('- [%s] %s asked: %s (%d files, status: %s)', $fr->created_at->diffForHumans(), $by, $fr->prompt, $fileCount, $fr->status);
        })->implode("\n");
    }

    /**
     * Reads the existing `tasks` table's current status distribution (the
     * only real sprint/task data DevPlan has — no new sprint concept is
     * introduced here) plus a pointer at the Task Plan module's own recorded
     * sprint breakdown when one exists, so Maya can answer "what's the state
     * of the sprint" without a dedicated sprint-tracking subsystem.
     */
    public function sprintStatus(Project $project): string
    {
        $tasks = $project->tasks()->with('assignee:id,name')->get();

        if ($tasks->isEmpty()) {
            return '(no tasks recorded yet)';
        }

        $byStatus = $tasks->groupBy('status');
        $counts = sprintf(
            '%d todo, %d in progress, %d done (%d total)',
            $byStatus->get('todo', collect())->count(),
            $byStatus->get('doing', collect())->count(),
            $byStatus->get('done', collect())->count(),
            $tasks->count(),
        );

        $inProgress = $byStatus->get('doing', collect())
            ->map(fn ($t) => sprintf('- %s%s', $t->title, $t->assignee ? " (assigned to {$t->assignee->name})" : ' (unassigned)'))
            ->implode("\n");

        $planModule = Module::where('project_id', $project->id)->where('module_type', 'task_plan')->first();
        $plannedSprintCount = $planModule ? ModuleItem::where('module_id', $planModule->id)->count() : 0;

        $lines = ["Task status: {$counts}"];
        if ($inProgress) {
            $lines[] = "Currently in progress:\n{$inProgress}";
        }
        if ($plannedSprintCount > 0) {
            $lines[] = "The Task Plan module also has {$plannedSprintCount} recorded sprint item(s).";
        }

        return implode("\n\n", $lines);
    }

    /**
     * The same tech-stack selection projectSpec() folds into one prose
     * paragraph, returned instead as plain values — used to ground
     * generation prompts in the real stack and by StackConformanceChecker
     * to check generated content against it. Empty strings (not missing
     * keys) when nothing was ever selected, so callers can treat the
     * result uniformly without isset() checks.
     *
     * @return array{frontend: string, backend: string, database: string}
     */
    public function techStack(Project $project): array
    {
        return $this->cache->rememberTechStack($project, function () use ($project) {
            $stackModule = Module::where('project_id', $project->id)->where('module_type', 'tech_stack')->first();
            $stackItem = $stackModule
                ? ModuleItem::where('module_id', $stackModule->id)->where('item_type', 'tech_stack')->first()
                : null;

            $s = $stackItem->content ?? [];

            return [
                'frontend' => $s['frontend']['selected'] ?? '',
                'backend' => $s['backend']['selected'] ?? '',
                'database' => $s['database']['selected'] ?? '',
            ];
        });
    }

    /**
     * A short, mechanically derived (no AI call) style/convention block —
     * fed into FeatureAgentService::generateContent() so generated files
     * match what's already there instead of the AI improvising its own
     * naming/layout per file. Two sources, in priority order:
     *
     *  1. The Folder Structure module's own scaffold tree, if the project
     *     recorded one — a real, explicit intended layout with real example
     *     file names (ProductController.php, ProductList.jsx), available
     *     from the earliest point in the project's life, before any code
     *     exists yet.
     *  2. Otherwise, a sample of already-indexed files' own paths and
     *     symbol names — real naming actually in use in the codebase today.
     *
     * This can't inspect indentation, quote style, or any other formatting
     * detail: ProjectFile only ever stores a path, an AI-derived summary,
     * and symbol names — never the file's raw content — so anything below
     * that granularity is genuinely unavailable here, not just unimplemented.
     */
    public function codingStandards(Project $project): ?string
    {
        return $this->cache->rememberCodingStandards(
            $project,
            fn () => $this->scaffoldTreeStandards($project) ?? $this->indexedFileStandards($project),
        );
    }

    private function scaffoldTreeStandards(Project $project): ?string
    {
        $module = Module::where('project_id', $project->id)->where('module_type', 'folder_structure')->first();
        $item = $module
            ? ModuleItem::where('module_id', $module->id)->where('item_type', 'scaffold_tree')->first()
            : null;

        $tree = $item?->content['tree'] ?? null;
        if (! is_array($tree)) {
            return null;
        }

        $paths = [];
        $this->flattenScaffoldTree($tree, '', $paths);
        if (! $paths) {
            return null;
        }

        $sample = array_slice($paths, 0, 20);

        return "This project's intended folder/file layout (from its Folder Structure plan):\n"
            .implode("\n", array_map(fn ($p) => "- {$p}", $sample));
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function flattenScaffoldTree(array $node, string $prefix, array &$paths): void
    {
        if (! isset($node['name'])) {
            return;
        }

        $path = $prefix !== '' ? "{$prefix}/{$node['name']}" : $node['name'];

        if (($node['type'] ?? null) === 'file') {
            $paths[] = $path;

            return;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->flattenScaffoldTree($child, $path, $paths);
            }
        }
    }

    private function indexedFileStandards(Project $project): ?string
    {
        $files = ProjectFile::where('project_id', $project->id)
            ->whereNotNull('symbols')
            ->orderByDesc('last_scanned_at')
            ->limit(15)
            ->get(['path', 'symbols']);

        $lines = $files
            ->flatMap(fn (ProjectFile $f) => collect($f->symbols ?? [])->map(fn ($symbol) => "{$f->path}: {$symbol}"))
            ->take(20);

        if ($lines->isEmpty()) {
            return null;
        }

        return "Real file/symbol naming already used in this project (match this style):\n"
            .$lines->map(fn ($line) => "- {$line}")->implode("\n");
    }

    /**
     * A lightweight, scoring-only companion to relatedFiles() — reports
     * whether that call found a genuine keyword match or would have had to
     * fall back to "just return some indexed files" (relatedFiles() itself
     * is left untouched since several other callers depend on its exact
     * return shape). Used only by ConfidenceScorer.
     *
     * @return array{matched: bool, keywordCount: int}
     */
    public function contextMatchQuality(Project $project, string $query): array
    {
        $keywords = $this->extractKeywords($query);
        if (! $keywords) {
            return ['matched' => false, 'keywordCount' => 0];
        }

        $matchedCount = ProjectFile::where('project_id', $project->id)
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('path', 'like', "%{$keyword}%")
                        ->orWhere('summary', 'like', "%{$keyword}%");
                }
            })
            ->count();

        return ['matched' => $matchedCount > 0, 'keywordCount' => count($keywords)];
    }

    private function extractKeywords(string $prompt): array
    {
        preg_match_all('/[A-Za-z]{4,}/', $prompt, $matches);
        $stopwords = ['that', 'this', 'with', 'from', 'have', 'should', 'would', 'could', 'able', 'when', 'also'];

        return array_values(array_unique(array_diff(array_map('strtolower', $matches[0]), $stopwords)));
    }
}
