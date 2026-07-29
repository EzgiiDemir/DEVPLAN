<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DevEngine\CodebaseIndexer;
use Illuminate\Http\Request;

class CodebaseController extends Controller
{
    public function __construct(private CodebaseIndexer $indexer) {}

    public function status(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return [
            'file_count' => $project->files()->count(),
            'dependency_count' => $project->files()->withCount('outgoingDependencies')->get()->sum('outgoing_dependencies_count'),
            'last_scanned_at' => $project->files()->max('last_scanned_at'),
        ];
    }

    public function files(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return $project->files()
            ->select(['id', 'path', 'language', 'summary', 'symbols', 'unresolved_imports', 'last_scanned_at'])
            ->orderBy('path')
            ->get();
    }

    public function diff(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'files' => ['required', 'array'],
            'files.*.path' => ['required', 'string'],
            'files.*.hash' => ['nullable', 'string'],
        ]);

        return $this->indexer->diff($project, $data['files']);
    }

    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*.path' => ['required', 'string'],
            'files.*.hash' => ['required', 'string'],
            'files.*.content' => ['required', 'string'],
        ]);

        return $this->indexer->indexFiles($project, $data['files']);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
