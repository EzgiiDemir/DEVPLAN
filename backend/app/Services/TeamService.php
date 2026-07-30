<?php

namespace App\Services;

use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * Idempotent: every user needs exactly one personal team to own their
     * solo projects and to have somewhere to land before/without ever
     * joining a real team. Used at registration and as a store()-time
     * fallback for users who predate this (covered by the accompanying
     * migration's own backfill, but kept here too as a safety net).
     */
    public function ensurePersonalTeam(User $user): Team
    {
        $existing = Team::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('personal', true)->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $team = Team::create(['name' => $user->name."'s Team", 'personal' => true]);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);

            return $team;
        });
    }

    public function invite(Team $team, string $email, string $role, User $inviter): TeamInvitation
    {
        $existingMember = TeamMember::where('team_id', $team->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($existingMember) {
            throw ValidationException::withMessages(['email' => ['This user is already a member of the team.']]);
        }

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => $email,
            'role' => $role,
            'token' => Str::random(48),
            'invited_by_user_id' => $inviter->id,
        ]);

        // With MAIL_MAILER=log in this environment this writes to the log
        // rather than a real inbox — the invitation record + shareable link
        // (built from the token) are the actual source of truth either way.
        Mail::to($email)->send(new TeamInvitationMail($invitation->load(['team', 'invitedBy'])));

        $this->audit->record($inviter, 'team.member_invited', ['email' => $email, 'role' => $role], team: $team);

        return $invitation;
    }

    public function accept(TeamInvitation $invitation, User $user): TeamMember
    {
        abort_if($invitation->accepted_at !== null, 410, 'This invitation has already been used.');
        abort_unless(strcasecmp($invitation->email, $user->email) === 0, 403, 'This invitation was sent to a different email address.');

        $member = DB::transaction(function () use ($invitation, $user) {
            $member = TeamMember::firstOrCreate(
                ['team_id' => $invitation->team_id, 'user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            return $member;
        });

        $this->audit->record($user, 'team.member_joined', ['role' => $member->role], team: $invitation->team);

        return $member;
    }

    public function changeRole(TeamMember $member, string $role, ?User $actor = null): TeamMember
    {
        if ($member->role === 'owner' && $role !== 'owner') {
            $remainingOwners = TeamMember::where('team_id', $member->team_id)
                ->where('role', 'owner')
                ->where('id', '!=', $member->id)
                ->exists();

            abort_unless($remainingOwners, 422, 'A team must always have at least one owner.');
        }

        $from = $member->role;
        $member->update(['role' => $role]);

        $this->audit->record($actor, 'team.role_changed', [
            'target_user_id' => $member->user_id,
            'from' => $from,
            'to' => $role,
        ], team: $member->team);

        return $member;
    }

    public function removeMember(TeamMember $member, ?User $actor = null): void
    {
        if ($member->role === 'owner') {
            $remainingOwners = TeamMember::where('team_id', $member->team_id)
                ->where('role', 'owner')
                ->where('id', '!=', $member->id)
                ->exists();

            abort_unless($remainingOwners, 422, 'A team must always have at least one owner.');
        }

        $this->audit->record($actor, 'team.member_removed', ['target_user_id' => $member->user_id], team: $member->team);

        $member->delete();
    }
}
