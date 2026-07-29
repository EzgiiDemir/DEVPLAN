<?php

namespace App\Services\DevEngine;

use App\Models\FileDependency;
use App\Models\Module;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Models\ProjectFile;
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
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($requests->isEmpty()) {
            return '(no feature history yet)';
        }

        return $requests->map(function ($fr) {
            $fileCount = $fr->changeSet?->files()->count() ?? 0;

            return sprintf('- [%s] %s (%d files, status: %s)', $fr->created_at->diffForHumans(), $fr->prompt, $fileCount, $fr->status);
        })->implode("\n");
    }

    private function extractKeywords(string $prompt): array
    {
        preg_match_all('/[A-Za-z]{4,}/', $prompt, $matches);
        $stopwords = ['that', 'this', 'with', 'from', 'have', 'should', 'would', 'could', 'able', 'when', 'also'];

        return array_values(array_unique(array_diff(array_map('strtolower', $matches[0]), $stopwords)));
    }
}
