<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Services\TeamService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(private TeamService $teams) {}

    public function index(Request $request)
    {
        return $request->user()->teams()->withCount('members')->get()
            ->map(fn (Team $team) => [
                ...$team->toArray(),
                'role' => $team->pivot->role,
            ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $team = Team::create(['name' => $data['name'], 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $request->user()->id, 'role' => 'owner']);

        return response()->json($team, 201);
    }

    public function update(Request $request, Team $team)
    {
        $this->authorizeManage($request, $team);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $team->update($data);

        return $team;
    }

    public function destroy(Request $request, Team $team)
    {
        $role = $team->members()->where('user_id', $request->user()->id)->value('role');
        abort_unless($role === 'owner', 403);
        abort_if($team->personal, 422, 'A personal team cannot be deleted.');
        abort_if($team->projects()->exists(), 422, 'Move or delete this team\'s projects before deleting the team.');

        $team->delete();

        return response()->noContent();
    }

    private function authorizeManage(Request $request, Team $team): void
    {
        $role = $team->members()->where('user_id', $request->user()->id)->value('role');
        abort_unless(in_array($role, ['admin', 'owner'], true), 403);
    }
}
