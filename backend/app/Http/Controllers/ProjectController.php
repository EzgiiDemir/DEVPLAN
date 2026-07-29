<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    private const MODULE_TYPES = [
        'idea', 'research', 'requirements', 'mvp_scope', 'tech_stack', 'design',
        'api_design', 'folder_structure', 'environment', 'task_plan',
        'ai_resources', 'prompt_engineering',
    ];

    public function index(Request $request)
    {
        return $request->user()->projects()->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $project = DB::transaction(function () use ($request, $data) {
            $project = $request->user()->projects()->create($data);

            foreach (self::MODULE_TYPES as $index => $moduleType) {
                $project->modules()->create([
                    'module_type' => $moduleType,
                    'status' => 'not_started',
                    'order_index' => $index + 1,
                ]);
            }

            return $project;
        });

        return response()->json($project->load('modules'), 201);
    }

    public function show(Request $request, Project $project)
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        return $project->load('modules.items');
    }

    public function update(Request $request, Project $project)
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'local_path' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $project->update($data);

        return $project;
    }

    /**
     * Separate from update() deliberately — this is written far more often
     * (every tab switch/cursor move, debounced client-side) and shouldn't be
     * coupled to title/description validation or risk racing with those edits.
     */
    public function updateWorkspaceState(Request $request, Project $project)
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'workspace_state' => ['required', 'array'],
            'workspace_state.openTabs' => ['sometimes', 'array'],
            'workspace_state.openTabs.*' => ['string'],
            'workspace_state.activeTab' => ['sometimes', 'nullable', 'string'],
            'workspace_state.cursorPositions' => ['sometimes', 'array'],
            'workspace_state.lastActiveFile' => ['sometimes', 'nullable', 'string'],
        ]);

        $project->update(['workspace_state' => $data['workspace_state']]);

        return $project;
    }

    public function destroy(Request $request, Project $project)
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->delete();

        return response()->noContent();
    }
}
