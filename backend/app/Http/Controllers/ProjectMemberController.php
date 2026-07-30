<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamMember;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('manage', $project);

        return $project->members()->with('user:id,name,email')->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['sometimes', 'nullable', 'string', 'in:owner,admin,developer,viewer'],
        ]);

        $teamRole = TeamMember::where('team_id', $project->team_id)->where('user_id', $data['user_id'])->value('role');
        abort_unless($teamRole !== null, 422, 'Only existing team members can be added to a project.');

        // An override may only narrow access, never grant more than the
        // user's team-wide role already permits — otherwise this would be a
        // privilege-escalation path around team roles.
        $rank = ['viewer' => 0, 'developer' => 1, 'admin' => 2, 'owner' => 3];
        if (! empty($data['role'])) {
            abort_unless($rank[$data['role']] <= $rank[$teamRole], 422, 'A project override cannot grant more access than the team role allows.');
        }

        $member = ProjectMember::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $data['user_id']],
            ['role' => $data['role'] ?? null],
        );

        return response()->json($member->load('user:id,name,email'), 201);
    }

    public function destroy(Request $request, Project $project, ProjectMember $member)
    {
        $this->authorize('manage', $project);
        abort_unless($member->project_id === $project->id, 404);

        $member->delete();

        return response()->noContent();
    }
}
