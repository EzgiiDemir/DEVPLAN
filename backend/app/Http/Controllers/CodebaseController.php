<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DevEngine\CodebaseIndexer;
use App\Services\DevEngine\IndexFreshnessChecker;
use App\Services\ProjectCache;
use Illuminate\Http\Request;

class CodebaseController extends Controller
{
    public function __construct(
        private CodebaseIndexer $indexer,
        private IndexFreshnessChecker $freshness,
        private ProjectCache $cache,
    ) {}

    /**
     * `current_head` is optional — the frontend passes it when Companion is
     * paired and reports a git HEAD; omitting it (no repo yet, not paired)
     * just skips the staleness check rather than failing. Only the file/
     * dependency counts are cached (30s) — `stale` depends on the specific
     * `current_head` this request passed and must always be computed fresh.
     */
    public function status(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $counts = $this->cache->rememberCodebaseStatus($project, fn () => [
            'file_count' => $project->files()->count(),
            'dependency_count' => $project->files()->withCount('outgoingDependencies')->get()->sum('outgoing_dependencies_count'),
            'last_scanned_at' => $project->files()->max('last_scanned_at'),
        ]);

        $currentHead = $request->query('current_head');
        $freshness = $this->freshness->check($project->last_known_git_head, $currentHead);

        return [
            ...$counts,
            'last_known_git_head' => $project->last_known_git_head,
            'stale' => $freshness['stale'],
        ];
    }

    public function files(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $project->files()
            ->select(['id', 'path', 'language', 'summary', 'symbols', 'unresolved_imports', 'last_scanned_at'])
            ->orderBy('path')
            ->get();
    }

    /**
     * `git_head` is optional — when the frontend reports it (Companion
     * paired, project is a real git repo), it's recorded as "the HEAD as of
     * the last time we checked", regardless of whether anything in
     * `files` actually needed reindexing.
     */
    public function diff(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'files' => ['required', 'array'],
            'files.*.path' => ['required', 'string'],
            'files.*.hash' => ['nullable', 'string'],
            'git_head' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (array_key_exists('git_head', $data)) {
            $project->update(['last_known_git_head' => $data['git_head']]);
        }

        $result = $this->indexer->diff($project, $data['files']);

        // diff() can delete ProjectFile rows for files no longer on disk —
        // the cached file/dependency counts would otherwise keep reporting
        // the pre-deletion numbers for up to 30s.
        if ($result['deleted']) {
            $this->cache->forgetCodebaseStatus($project);
        }

        return $result;
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*.path' => ['required', 'string'],
            'files.*.hash' => ['required', 'string'],
            'files.*.content' => ['required', 'string'],
        ]);

        $result = $this->indexer->indexFiles($project, $data['files']);

        // New/changed files can add symbols indexedFileStandards() would
        // pick up, and always change file_count/last_scanned_at.
        $this->cache->forgetProjectContext($project);
        $this->cache->forgetCodebaseStatus($project);

        return $result;
    }
}
