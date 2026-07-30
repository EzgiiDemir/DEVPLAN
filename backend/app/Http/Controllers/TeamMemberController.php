<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Services\TeamService;
use Illuminate\Http\Request;

class TeamMemberController extends Controller
{
    public function __construct(private TeamService $teams) {}

    public function index(Request $request, Team $team)
    {
        $this->authorizeMember($request, $team);

        return $team->members()->with('user:id,name,email')->get();
    }

    public function invite(Request $request, Team $team)
    {
        $this->authorizeManage($request, $team);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:owner,admin,developer,viewer'],
        ]);

        $invitation = $this->teams->invite($team, $data['email'], $data['role'], $request->user());

        return response()->json($invitation, 201);
    }

    public function update(Request $request, Team $team, TeamMember $member)
    {
        $this->authorizeManage($request, $team);
        abort_unless($member->team_id === $team->id, 404);

        $data = $request->validate(['role' => ['required', 'string', 'in:owner,admin,developer,viewer']]);

        return $this->teams->changeRole($member, $data['role'], $request->user());
    }

    public function destroy(Request $request, Team $team, TeamMember $member)
    {
        $this->authorizeManage($request, $team);
        abort_unless($member->team_id === $team->id, 404);

        $this->teams->removeMember($member, $request->user());

        return response()->noContent();
    }

    private function authorizeMember(Request $request, Team $team): void
    {
        abort_unless($team->members()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function authorizeManage(Request $request, Team $team): void
    {
        $role = $team->members()->where('user_id', $request->user()->id)->value('role');
        abort_unless(in_array($role, ['admin', 'owner'], true), 403);
    }
}
