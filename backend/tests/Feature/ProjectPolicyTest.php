<?php

namespace Tests\Feature;

use App\Models\ProjectMember;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function teamProject(): array
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();

        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $developer->id, 'role' => 'developer']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Shared Project']);

        return compact('owner', 'developer', 'viewer', 'outsider', 'team', 'project');
    }

    public function test_a_viewer_can_read_but_not_mutate_a_project(): void
    {
        ['viewer' => $viewer, 'project' => $project] = $this->teamProject();

        $this->actingAs($viewer)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->actingAs($viewer)->patchJson("/api/v1/projects/{$project->id}", ['title' => 'New Title'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_developer_can_mutate_but_not_delete_a_project(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->teamProject();

        $this->actingAs($developer)->patchJson("/api/v1/projects/{$project->id}", ['title' => 'New Title'])->assertOk();
        $this->actingAs($developer)->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_only_the_owner_can_delete_a_project(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->teamProject();

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();
    }

    public function test_a_non_member_cannot_see_the_project_at_all(): void
    {
        ['outsider' => $outsider, 'project' => $project] = $this->teamProject();

        $this->actingAs($outsider)->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_viewer_cannot_send_maya_messages_or_create_feature_requests(): void
    {
        ['viewer' => $viewer, 'project' => $project] = $this->teamProject();

        $this->actingAs($viewer)
            ->postJson("/api/v1/projects/{$project->id}/maya/messages", ['message' => 'hello'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson("/api/v1/projects/{$project->id}/features", ['prompt' => 'add a button'])
            ->assertForbidden();
    }

    public function test_projects_index_only_returns_projects_from_the_users_teams(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->teamProject();

        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'Other Team', 'personal' => false]);
        TeamMember::create(['team_id' => $otherTeam->id, 'user_id' => $otherUser->id, 'role' => 'owner']);
        $otherUser->projects()->create(['team_id' => $otherTeam->id, 'title' => 'Not Visible']);

        $response = $this->actingAs($developer)->getJson('/api/v1/projects');
        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($project->id, $response->json()[0]['id']);
    }

    /**
     * ProjectController::index() resolves every returned project's my_role
     * in a batch (ProjectPolicy::rolesFor()) rather than once per project —
     * this pins down that the batched path still reproduces roleFor()'s
     * per-project logic exactly, including the ProjectMember-restriction
     * edge case, across multiple projects with different outcomes at once.
     */
    public function test_index_reports_the_correct_my_role_for_each_project_including_a_restricted_one(): void
    {
        ['owner' => $owner, 'developer' => $developer, 'team' => $team, 'project' => $restrictedProject] = $this->teamProject();
        $openProject = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Open Project']);

        // Restrict $restrictedProject to the owner only — $developer has no
        // ProjectMember row of their own, so once ANY row exists for that
        // project, they lose access to it (but keep their team-wide role on
        // the still-unrestricted $openProject).
        ProjectMember::create(['project_id' => $restrictedProject->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $response = $this->actingAs($developer)->getJson('/api/v1/projects');
        $response->assertOk()->assertJsonCount(2);
        $developerRoles = collect($response->json())->pluck('my_role', 'id');
        $this->assertNull($developerRoles[$restrictedProject->id]);
        $this->assertSame('developer', $developerRoles[$openProject->id]);

        $ownerResponse = $this->actingAs($owner)->getJson('/api/v1/projects');
        $ownerResponse->assertOk()->assertJsonCount(2);
        $ownerRoles = collect($ownerResponse->json())->pluck('my_role', 'id');
        $this->assertSame('owner', $ownerRoles[$restrictedProject->id]);
        $this->assertSame('owner', $ownerRoles[$openProject->id]);
    }

    /**
     * Locks in the fix for a real N+1: ProjectController::index() used to
     * call roleFor() once per project in a map(), each call running up to
     * three queries of its own, so query count scaled with project count.
     * ProjectPolicy::rolesFor() batches that into a fixed number of queries
     * regardless of how many projects are being listed — this proves it by
     * asserting the count listing 6 projects isn't any higher than listing 1.
     */
    public function test_listing_projects_does_not_run_more_queries_as_project_count_grows(): void
    {
        ['owner' => $owner, 'team' => $team, 'project' => $firstProject] = $this->teamProject();

        $countQueries = function () use ($owner) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->actingAs($owner)->getJson('/api/v1/projects')->assertOk();

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };

        $queriesForOneProject = $countQueries();

        for ($i = 0; $i < 5; $i++) {
            $owner->projects()->create(['team_id' => $team->id, 'title' => "Extra Project {$i}"]);
        }

        $queriesForSixProjects = $countQueries();

        $this->assertLessThanOrEqual($queriesForOneProject, $queriesForSixProjects);
    }
}
