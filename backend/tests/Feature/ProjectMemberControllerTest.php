<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMemberControllerTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $memberA = User::factory()->create();
        $memberB = User::factory()->create();

        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $memberA->id, 'role' => 'developer']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $memberB->id, 'role' => 'developer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Restricted Project']);

        return compact('owner', 'memberA', 'memberB', 'team', 'project');
    }

    public function test_a_project_with_no_overrides_is_visible_to_the_whole_team(): void
    {
        ['memberA' => $memberA, 'project' => $project] = $this->scenario();

        $this->actingAs($memberA)->getJson("/api/projects/{$project->id}")->assertOk();
    }

    public function test_once_restricted_a_non_listed_developer_loses_access_but_the_owner_keeps_it(): void
    {
        ['owner' => $owner, 'memberA' => $memberA, 'memberB' => $memberB, 'project' => $project] = $this->scenario();

        $this->actingAs($owner)->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $memberA->id,
        ])->assertCreated();

        $this->actingAs($memberA)->getJson("/api/projects/{$project->id}")->assertOk();
        $this->actingAs($memberB)->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/projects/{$project->id}")->assertOk();
    }

    public function test_a_project_override_cannot_exceed_the_users_team_role(): void
    {
        ['owner' => $owner, 'memberA' => $memberA, 'project' => $project] = $this->scenario();

        $this->actingAs($owner)->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $memberA->id,
            'role' => 'owner',
        ])->assertStatus(422);
    }

    public function test_only_admin_or_owner_can_manage_project_members(): void
    {
        ['memberA' => $memberA, 'memberB' => $memberB, 'project' => $project] = $this->scenario();

        $this->actingAs($memberA)->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $memberB->id,
        ])->assertForbidden();
    }
}
