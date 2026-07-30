<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Services\TeamService;
use Illuminate\Http\Request;

class TeamInvitationController extends Controller
{
    public function __construct(private TeamService $teams) {}

    /**
     * Deliberately unauthenticated-safe to look up by token (the frontend
     * shows "You're invited to X" before asking the visitor to log in), but
     * never reveals anything beyond team name/role/inviter.
     */
    public function show(Request $request, string $token)
    {
        $invitation = TeamInvitation::where('token', $token)->with(['team', 'invitedBy'])->firstOrFail();

        return [
            'team_name' => $invitation->team->name,
            'role' => $invitation->role,
            'invited_by' => $invitation->invitedBy->name,
            'email' => $invitation->email,
            'accepted' => $invitation->accepted_at !== null,
        ];
    }

    public function accept(Request $request, string $token)
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        $member = $this->teams->accept($invitation, $request->user());

        return response()->json($member->load('team'), 201);
    }
}
