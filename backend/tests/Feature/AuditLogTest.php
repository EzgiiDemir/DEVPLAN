<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_team_members_role_writes_a_real_audit_row(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $membership = TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'developer']);

        $this->actingAs($owner)
            ->patchJson("/api/v1/teams/{$team->id}/members/{$membership->id}", ['role' => 'admin'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'team_id' => $team->id,
            'action' => 'team.role_changed',
        ]);
    }

    public function test_deleting_a_project_writes_a_real_audit_row(): void
    {
        $owner = User::factory()->create();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['title' => 'Delete Me'])->json();

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project['id']}")->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'project_id' => $project['id'],
            'action' => 'project.deleted',
        ]);
    }

    public function test_the_ai_feature_approval_pipeline_writes_three_audit_rows(): void
    {
        $owner = User::factory()->create();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['title' => 'Feature Audit'])->json();

        $this->mock(\App\Services\AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app.js', 'action' => 'create', 'reason' => 'entrypoint']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn('console.log("entry");');
        });

        $store = $this->actingAs($owner)->postJson("/api/v1/projects/{$project['id']}/features", ['prompt' => 'add an entrypoint']);
        $store->assertStatus(202);
        // store()/generate() now return 202 + a job id (Subsystem 3 — Queue
        // System); the `sync` queue driver used in tests runs the job inline
        // before this returns, so the AiJob row is already 'succeeded'.
        $job = $this->actingAs($owner)->getJson("/api/v1/ai-jobs/{$store->json('job_id')}");
        $featureRequestId = $job->json('result.feature_request_id');

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project['id']}/features/{$featureRequestId}/plan/approve", [
            'approved_paths' => ['app.js'],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project['id']}/features/{$featureRequestId}/generate", []);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project['id']}/features/{$featureRequestId}/diff/approve", [
            'approved_paths' => ['app.js'],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project['id']}/features/{$featureRequestId}/apply", [
            'applied_paths' => ['app.js'],
            'before' => ['hash' => str_repeat('a', 40), 'message' => 'before'],
            'after' => ['hash' => str_repeat('b', 40), 'message' => 'after'],
        ])->assertOk();

        foreach (['feature.plan_approved', 'feature.diff_approved', 'feature.applied'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $owner->id,
                'project_id' => $project['id'],
                'action' => $action,
            ]);
        }
    }
}
