<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_view_a_projects_own_audit_history_without_leakage_from_other_projects(): void
    {
        $owner = User::factory()->create();
        $projectA = $this->actingAs($owner)->postJson('/api/projects', ['title' => 'Project A'])->json();
        $projectB = $this->actingAs($owner)->postJson('/api/projects', ['title' => 'Project B'])->json();

        $this->actingAs($owner)->postJson("/api/projects/{$projectA['id']}/audit/commands", [
            'type' => 'command',
            'command' => 'npm install',
            'risk_level' => 'safe',
        ])->assertNoContent();

        $responseA = $this->actingAs($owner)->getJson("/api/projects/{$projectA['id']}/audit");
        $responseA->assertOk();
        $this->assertGreaterThanOrEqual(1, count($responseA->json()));

        $responseB = $this->actingAs($owner)->getJson("/api/projects/{$projectB['id']}/audit");
        $responseB->assertOk()->assertJsonCount(0);
    }

    public function test_a_developer_cannot_view_the_projects_audit_history(): void
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $developer->id, 'role' => 'developer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Audit History Test']);

        $this->actingAs($developer)->getJson("/api/projects/{$project->id}/audit")->assertForbidden();
    }

    public function test_a_user_can_see_their_own_auth_history_via_me(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => bcrypt('password123')]);
        $this->postJson('/api/login', ['email' => 'me@example.com', 'password' => 'password123'])->assertOk();

        $response = $this->getJson('/api/security/audit/me');
        $response->assertOk();
        $actions = collect($response->json())->pluck('action');
        $this->assertTrue($actions->contains('auth.login'));
    }

    public function test_project_scoped_history_can_be_filtered_by_action(): void
    {
        $owner = User::factory()->create();
        $project = $this->actingAs($owner)->postJson('/api/projects', ['title' => 'Filter Test'])->json();
        $this->actingAs($owner)->postJson("/api/projects/{$project['id']}/audit/commands", [
            'type' => 'command',
            'command' => 'npm install',
            'risk_level' => 'safe',
        ]);

        $matching = $this->actingAs($owner)->getJson("/api/projects/{$project['id']}/audit?action=project.deleted");
        $matching->assertOk()->assertJsonCount(0);

        $realMatch = $this->actingAs($owner)->getJson("/api/projects/{$project['id']}/audit?action=companion.command_executed");
        $realMatch->assertOk()->assertJsonCount(1);
    }
}
