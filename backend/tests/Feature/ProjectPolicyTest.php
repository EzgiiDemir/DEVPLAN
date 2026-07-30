<?php

namespace Tests\Feature;

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

        $this->actingAs($viewer)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->actingAs($viewer)->patchJson("/api/projects/{$project->id}", ['title' => 'New Title'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_developer_can_mutate_but_not_delete_a_project(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->teamProject();

        $this->actingAs($developer)->patchJson("/api/projects/{$project->id}", ['title' => 'New Title'])->assertOk();
        $this->actingAs($developer)->deleteJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_only_the_owner_can_delete_a_project(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->teamProject();

        $this->actingAs($owner)->deleteJson("/api/projects/{$project->id}")->assertNoContent();
    }

    public function test_a_non_member_cannot_see_the_project_at_all(): void
    {
        ['outsider' => $outsider, 'project' => $project] = $this->teamProject();

        $this->actingAs($outsider)->getJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_a_viewer_cannot_send_maya_messages_or_create_feature_requests(): void
    {
        ['viewer' => $viewer, 'project' => $project] = $this->teamProject();

        $this->actingAs($viewer)
            ->postJson("/api/projects/{$project->id}/maya/messages", ['message' => 'hello'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson("/api/projects/{$project->id}/features", ['prompt' => 'add a button'])
            ->assertForbidden();
    }

    public function test_projects_index_only_returns_projects_from_the_users_teams(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->teamProject();

        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'Other Team', 'personal' => false]);
        TeamMember::create(['team_id' => $otherTeam->id, 'user_id' => $otherUser->id, 'role' => 'owner']);
        $otherUser->projects()->create(['team_id' => $otherTeam->id, 'title' => 'Not Visible']);

        $response = $this->actingAs($developer)->getJson('/api/projects');
        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($project->id, $response->json()[0]['id']);
    }
}
