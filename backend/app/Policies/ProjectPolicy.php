<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Collection;

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
        return $this->rolesFor($user, collect([$project]))[$project->id] ?? null;
    }

    /**
     * Batched form of roleFor() — used by ProjectController::index(), which
     * used to call roleFor() once per project in a map(), each call running
     * up to three queries (ProjectMember lookup, TeamMember lookup,
     * ProjectMember-exists check). For a user with N projects that was 1 +
     * up to 3N queries; this resolves every project's role in exactly
     * three, regardless of N, then does the same per-project logic roleFor()
     * always did — just against already-fetched, keyed-by-id collections
     * instead of a fresh query each time.
     *
     * @return array<int, ?string> role keyed by project id
     */
    public function rolesFor(User $user, Collection $projects): array
    {
        $projectIds = $projects->pluck('id');
        $teamIds = $projects->pluck('team_id')->filter()->unique();

        $memberRowsByProject = ProjectMember::whereIn('project_id', $projectIds)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('project_id');

        $teamRoleByTeam = TeamMember::whereIn('team_id', $teamIds)
            ->where('user_id', $user->id)
            ->pluck('role', 'team_id');

        $restrictedProjectIds = ProjectMember::whereIn('project_id', $projectIds)
            ->distinct()
            ->pluck('project_id')
            ->flip();

        return $projects->mapWithKeys(function (Project $project) use ($memberRowsByProject, $teamRoleByTeam, $restrictedProjectIds) {
            $memberRow = $memberRowsByProject->get($project->id);
            $teamRole = $project->team_id ? ($teamRoleByTeam[$project->team_id] ?? null) : null;

            // A row's mere existence opts this user IN even if its role is
            // null ("use my team-wide role") — do not confuse "no row" with
            // "row with a null role", or every null-role override silently
            // loses access instead of falling back to the team role.
            if ($memberRow) {
                $role = $memberRow->role ?? $teamRole;
            } elseif ($restrictedProjectIds->has($project->id) && ! in_array($teamRole, ['admin', 'owner'], true)) {
                $role = null;
            } else {
                $role = $teamRole;
            }

            return [$project->id => $role];
        })->all();
    }
}
