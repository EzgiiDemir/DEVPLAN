<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Notifications\DeploymentFinishedNotification;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeploymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Deploy Test']);

        return Project::findOrFail($response->json('id'));
    }

    private function seedEnvExample(Project $project, string $envExample): void
    {
        $envModule = $project->modules()->where('module_type', 'environment')->firstOrFail();
        $envModule->items()->create([
            'item_type' => 'env_files',
            'content' => ['envExample' => $envExample],
            'is_ai_generated' => true,
        ]);
    }

    public function test_analyze_reports_missing_config_and_real_required_env_vars(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('This project is missing a vercel.json and needs 2 environment variables before it can deploy.');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $this->seedEnvExample($project, "DATABASE_URL=\nAPI_SECRET_KEY=\n# a comment, not a var\nPORT=3000\n");

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/analyze", [
            'platform' => 'vercel',
            'has_vercel_json' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('platform_ready', false)
            ->assertJsonPath('missing_config_files', ['vercel.json']);

        $vars = $response->json('required_env_vars');
        $this->assertContains('DATABASE_URL', $vars);
        $this->assertContains('API_SECRET_KEY', $vars);
        $this->assertContains('PORT', $vars);
        $this->assertCount(3, $vars);
        $this->assertNotEmpty($response->json('summary'));
    }

    public function test_analyze_reports_ready_when_config_file_already_exists(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('Looks ready to deploy.');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/analyze", [
            'platform' => 'railway',
            'has_railway_json' => true,
        ]);

        $response->assertOk()->assertJsonPath('platform_ready', true)->assertJsonPath('missing_config_files', []);
    }

    public function test_analyze_warns_when_environment_module_was_never_completed(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('summary');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/analyze", [
            'platform' => 'docker',
            'has_dockerfile' => true,
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('required_env_vars'));
        $this->assertNotEmpty($response->json('warnings'));
    }

    public function test_render_never_reports_missing_config_since_it_has_none_to_check(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('summary');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/analyze", [
            'platform' => 'render',
        ]);

        $response->assertOk()->assertJsonPath('platform_ready', true);
    }

    public function test_analyze_requires_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)
            ->postJson("/api/v1/projects/{$project->id}/deployments/analyze", ['platform' => 'vercel'])
            ->assertForbidden();
    }

    public function test_generate_config_delegates_to_the_existing_plan_pipeline_with_deploy_config_intent(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'vercel.json', 'action' => 'create', 'reason' => 'configure the Vercel build']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/generate-config", [
            'path' => 'vercel.json',
            'platform' => 'vercel',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('change_set'));

        $assistantMessage = collect($response->json('messages'))->firstWhere('role', 'assistant');
        $this->assertSame('deploy_config', $assistantMessage['intent']);
        $this->assertDatabaseHas('change_set_files', ['path' => 'vercel.json']);
    }

    public function test_full_deployment_lifecycle_creates_and_links_a_real_checkpoint_on_success(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", [
            'platform' => 'vercel',
        ]);
        $started->assertCreated()->assertJsonPath('status', 'preparing');
        $deploymentId = $started->json('id');

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'building',
            'log_output' => 'Running build...',
        ])->assertOk()->assertJsonPath('status', 'building');

        $finished = $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'success',
            'git_commit_hash' => str_repeat('a', 40),
            'live_url' => 'https://my-app.vercel.app',
            'log_output' => 'Deployed successfully.',
        ]);

        $finished->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('live_url', 'https://my-app.vercel.app');
        $this->assertNotNull($finished->json('checkpoint_id'));

        $this->assertDatabaseHas('checkpoints', [
            'project_id' => $project->id,
            'git_commit_hash' => str_repeat('a', 40),
            'message' => 'Deployed to vercel',
        ]);

        $history = $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}/deployments");
        $history->assertOk()->assertJsonCount(1);
        $this->assertNotNull($history->json()[0]['checkpoint']);
    }

    public function test_a_failed_deployment_does_not_create_a_checkpoint(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'railway']);
        $deploymentId = $started->json('id');

        $finished = $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'failed',
            'error_message' => 'Build failed: missing dependency.',
            'log_output' => 'npm ERR! missing dependency',
        ]);

        $finished->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('checkpoint_id', null);
        $this->assertDatabaseCount('checkpoints', 0);
    }

    public function test_deployment_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/v1/projects/{$project->id}/deployments")->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel'])->assertForbidden();
    }

    /**
     * Covers Subsystem 10 (Deployment Hardening): the analyzer can only
     * verify a fact it actually has — whether the project has migration
     * files indexed at all — not whether they've been run against whatever
     * database the deploy target uses.
     */
    public function test_analyze_warns_about_indexed_migrations_needing_to_run(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('summary');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'database/migrations/2026_01_01_create_widgets_table.php',
            'language' => 'php',
            'content_hash' => 'x',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/analyze", [
            'platform' => 'railway',
            'has_railway_json' => true,
        ]);

        $response->assertOk()->assertJsonPath('migration_count', 1);
        $this->assertStringContainsString('migration', implode(' ', $response->json('warnings')));
    }

    public function test_health_check_records_healthy_for_a_responding_url(): void
    {
        Http::fake(['https://my-app.vercel.app' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');
        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'success',
            'live_url' => 'https://my-app.vercel.app',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}/health-check");

        $response->assertOk()->assertJsonPath('health_status', 'healthy');
        $this->assertNotNull($response->json('last_health_checked_at'));
    }

    public function test_health_check_records_unhealthy_for_an_unreachable_url(): void
    {
        Http::fake(['https://my-app.vercel.app' => Http::response('Server Error', 500)]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');
        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'success',
            'live_url' => 'https://my-app.vercel.app',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}/health-check");

        $response->assertOk()->assertJsonPath('health_status', 'unhealthy');
    }

    public function test_health_check_requires_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $started = $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');

        $this->actingAs($intruder)
            ->postJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}/health-check")
            ->assertForbidden();
    }

    public function test_a_hardcoded_looking_token_in_log_output_is_redacted_before_being_stored(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');

        $response = $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'building',
            'log_output' => "Logging in...\nAPI_KEY=sk_live_abcdefgh12345678\nBuild started.",
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('sk_live_abcdefgh12345678', $response->json('log_output'));
        $this->assertStringContainsString('[REDACTED]', $response->json('log_output'));
        $this->assertStringContainsString('Build started.', $response->json('log_output'));
    }

    public function test_the_companion_process_id_is_recorded_and_cleared_once_the_deploy_finishes(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');

        $building = $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'building',
            'companion_process_id' => '42',
        ]);
        $building->assertOk()->assertJsonPath('companion_process_id', '42');

        $finished = $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'success',
        ]);
        $finished->assertOk()->assertJsonPath('companion_process_id', null);
    }

    /**
     * Covers Subsystem 12 (Production Logging): deployment status
     * transitions write a structured log line, not just the DB row update —
     * so an ops log stream shows deployment activity without querying the
     * database.
     */
    public function test_deployment_status_transitions_are_logged(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);
        $deploymentId = $started->json('id');

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'success',
            'live_url' => 'https://my-app.vercel.app',
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'deployment.status_changed' && $context['status'] === 'preparing')
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'deployment.status_changed' && $context['status'] === 'success')
            ->once();
    }

    /**
     * Covers the notification system: a deployment that finishes (success
     * or failure) notifies whoever triggered it — a real deployment runs
     * for real minutes via a background Companion process, and a user who
     * navigated away previously had no way to find out it was done.
     */
    public function test_a_successful_deployment_notifies_the_user_who_started_it(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'vercel']);

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$started->json('id')}", [
            'status' => 'success',
            'live_url' => 'https://my-app.vercel.app',
        ]);

        Notification::assertSentTo($user, DeploymentFinishedNotification::class, fn ($n, $channels) => true);
    }

    public function test_a_failed_deployment_also_notifies_the_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $started = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/deployments", ['platform' => 'railway']);

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->id}/deployments/{$started->json('id')}", [
            'status' => 'failed',
            'error_message' => 'Build failed.',
        ]);

        Notification::assertSentTo($user, DeploymentFinishedNotification::class);
    }
}
