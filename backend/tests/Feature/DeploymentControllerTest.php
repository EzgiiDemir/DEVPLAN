<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Deploy Test']);

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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments/analyze", [
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments/analyze", [
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments/analyze", [
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments/analyze", [
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
            ->postJson("/api/projects/{$project->id}/deployments/analyze", ['platform' => 'vercel'])
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments/generate-config", [
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

        $started = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments", [
            'platform' => 'vercel',
        ]);
        $started->assertCreated()->assertJsonPath('status', 'preparing');
        $deploymentId = $started->json('id');

        $this->actingAs($user)->patchJson("/api/projects/{$project->id}/deployments/{$deploymentId}", [
            'status' => 'building',
            'log_output' => 'Running build...',
        ])->assertOk()->assertJsonPath('status', 'building');

        $finished = $this->actingAs($user)->patchJson("/api/projects/{$project->id}/deployments/{$deploymentId}", [
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

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/deployments");
        $history->assertOk()->assertJsonCount(1);
        $this->assertNotNull($history->json()[0]['checkpoint']);
    }

    public function test_a_failed_deployment_does_not_create_a_checkpoint(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $started = $this->actingAs($user)->postJson("/api/projects/{$project->id}/deployments", ['platform' => 'railway']);
        $deploymentId = $started->json('id');

        $finished = $this->actingAs($user)->patchJson("/api/projects/{$project->id}/deployments/{$deploymentId}", [
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

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/deployments")->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/projects/{$project->id}/deployments", ['platform' => 'vercel'])->assertForbidden();
    }
}
