<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Policies\ProjectPolicy;
use App\Services\AuditLogService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    private const MODULE_TYPES = [
        'idea', 'research', 'requirements', 'mvp_scope', 'tech_stack', 'design',
        'api_design', 'folder_structure', 'environment', 'task_plan',
        'ai_resources', 'prompt_engineering',
    ];

    public function __construct(
        private TeamService $teams,
        private ProjectPolicy $policy,
        private AuditLogService $audit,
    ) {}

    public function index(Request $request)
    {
        $teamIds = $request->user()->teams()->pluck('teams.id');

        return Project::whereIn('team_id', $teamIds)->with('team')->latest()->get()
            ->map(fn (Project $project) => $this->withRole($request, $project));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
        ]);

        if (! empty($data['team_id'])) {
            $team = Team::findOrFail($data['team_id']);
            $role = $team->members()->where('user_id', $request->user()->id)->value('role');
            abort_unless(in_array($role, ['developer', 'admin', 'owner'], true), 403);
        } else {
            $team = $this->teams->ensurePersonalTeam($request->user());
        }

        $project = DB::transaction(function () use ($request, $data, $team) {
            $project = $request->user()->projects()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'team_id' => $team->id,
            ]);

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
        $this->authorize('view', $project);

        return $this->withRole($request, $project->load('modules.items'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('act', $project);

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
        $this->authorize('act', $project);

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
        $this->authorize('delete', $project);

        $this->audit->record($request->user(), 'project.deleted', ['title' => $project->title], project: $project);

        $project->delete();

        return response()->noContent();
    }

    /**
     * Attaches the requesting user's effective role as a plain "my_role"
     * attribute so the frontend can gate/disable actions without a second
     * round-trip or re-implementing ProjectPolicy's role resolution itself.
     */
    private function withRole(Request $request, Project $project): Project
    {
        $project->setAttribute('my_role', $this->policy->roleFor($request->user(), $project));

        return $project;
    }
}
