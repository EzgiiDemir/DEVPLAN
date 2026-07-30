<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DevEngine\CodebaseIndexer;
use App\Services\DevEngine\IndexFreshnessChecker;
use Illuminate\Http\Request;

class CodebaseController extends Controller
{
    public function __construct(
        private CodebaseIndexer $indexer,
        private IndexFreshnessChecker $freshness,
    ) {}

    /**
     * `current_head` is optional — the frontend passes it when Companion is
     * paired and reports a git HEAD; omitting it (no repo yet, not paired)
     * just skips the staleness check rather than failing.
     */
    public function status(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $currentHead = $request->query('current_head');
        $freshness = $this->freshness->check($project->last_known_git_head, $currentHead);

        return [
            'file_count' => $project->files()->count(),
            'dependency_count' => $project->files()->withCount('outgoingDependencies')->get()->sum('outgoing_dependencies_count'),
            'last_scanned_at' => $project->files()->max('last_scanned_at'),
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

        return $this->indexer->diff($project, $data['files']);
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

        return $this->indexer->indexFiles($project, $data['files']);
    }
}
