<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamMember;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Any team member, at any role, can view. Viewers exist to read, not act.
     */
    public function view(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project) !== null;
    }

    /**
     * Developer and above can create/mutate/trigger AI, tests, and deploys.
     * Viewers are read-only by design.
     */
    public function act(User $user, Project $project): bool
    {
        return in_array($this->roleFor($user, $project), ['developer', 'admin', 'owner'], true);
    }

    /**
     * Admin and above can manage membership and project-level sharing.
     */
    public function manage(User $user, Project $project): bool
    {
        return in_array($this->roleFor($user, $project), ['admin', 'owner'], true);
    }

    /**
     * Only the project's owner can delete it.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project) === 'owner';
    }

    /**
     * Resolves the effective role a user has on a project: a per-project
     * ProjectMember override if one exists for them, otherwise their
     * team-wide role — except that once ANY ProjectMember row exists for a
     * project (an opt-in restriction), non-admin/owner team members with no
     * row of their own are excluded rather than falling back to their team
     * role. Admins/owners always retain access regardless of overrides.
     */
    /**
     * Exposed publicly (not just used internally by view/act/manage/delete)
     * so controllers can attach "my_role" to a project response for the
     * frontend to gate buttons on, without duplicating this resolution logic.
     */
    public function roleFor(User $user, Project $project): ?string
    {
        $memberRow = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        $teamRole = $project->team_id
            ? TeamMember::where('team_id', $project->team_id)
                ->where('user_id', $user->id)
                ->value('role')
            : null;

        // A row's mere existence opts this user IN even if its role is
        // null ("use my team-wide role") — do not confuse "no row" with
        // "row with a null role", or every null-role override silently
        // loses access instead of falling back to the team role.
        if ($memberRow) {
            return $memberRow->role ?? $teamRole;
        }

        $isRestricted = ProjectMember::where('project_id', $project->id)->exists();
        if ($isRestricted && ! in_array($teamRole, ['admin', 'owner'], true)) {
            return null;
        }

        return $teamRole;
    }
}
