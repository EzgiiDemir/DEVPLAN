<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    private function moduleIdFor($user, string $moduleType): int
    {
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'AI Test Project']);
        $modules = collect($project->json('modules'));

        return $modules->firstWhere('module_type', $moduleType)['id'];
    }

    public function test_pitch_returns_the_ai_generated_text(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('A sharp elevator pitch.');
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/ai/pitch', [
            'canvas' => ['problem' => ['X'], 'solution' => ['Y'], 'customer' => ['Z']],
            'tone' => 'short',
            'locale' => 'en',
        ]);

        $response->assertOk()->assertJsonPath('pitch', 'A sharp elevator pitch.');
    }

    public function test_user_stories_parses_ai_json_and_returns_expected_shape(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'stories' => [
                    ['feature' => 'Login', 'story' => 'As a user, I want to log in.', 'priority' => 'must'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $moduleId = $this->moduleIdFor($user, 'requirements');

        $response = $this->actingAs($user)->postJson('/api/ai/user-stories', [
            'module_id' => $moduleId,
            'features' => ['Login'],
            'locale' => 'en',
        ]);

        $response->assertOk()
            ->assertJsonPath('stories.0.feature', 'Login')
            ->assertJsonPath('stories.0.priority', 'must');
    }

    public function test_returns_502_when_ai_response_is_not_valid_json(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('this is not json at all');
        });

        $user = User::factory()->create();
        $moduleId = $this->moduleIdFor($user, 'requirements');

        $response = $this->actingAs($user)->postJson('/api/ai/user-stories', [
            'module_id' => $moduleId,
            'features' => ['Login'],
            'locale' => 'en',
        ]);

        $response->assertStatus(502)->assertJsonStructure(['message']);
    }

    public function test_returns_502_when_the_ai_provider_throws(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andThrow(new RuntimeException('Provider is down'));
        });

        $user = User::factory()->create();
        $moduleId = $this->moduleIdFor($user, 'requirements');

        $response = $this->actingAs($user)->postJson('/api/ai/user-stories', [
            'module_id' => $moduleId,
            'features' => ['Login'],
            'locale' => 'en',
        ]);

        $response->assertStatus(502)->assertJsonPath('message', 'Provider is down');
    }

    public function test_cannot_generate_for_a_module_belonging_to_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->moduleIdFor($owner, 'requirements');

        $response = $this->actingAs($intruder)->postJson('/api/ai/user-stories', [
            'module_id' => $moduleId,
            'features' => ['Login'],
            'locale' => 'en',
        ]);

        $response->assertForbidden();
    }

    public function test_mvp_recommendation_picks_up_requirement_story_context(): void
    {
        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Context Test']);
        $modules = collect($project->json('modules'));
        $requirementsId = $modules->firstWhere('module_type', 'requirements')['id'];
        $mvpId = $modules->firstWhere('module_type', 'mvp_scope')['id'];

        $this->actingAs($user)->postJson("/api/modules/{$requirementsId}/items", [
            'item_type' => 'requirement',
            'content' => ['feature' => 'Login', 'story' => 'As a user, I want to log in.', 'priority' => 'must'],
        ]);

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(fn (string $system, string $prompt) => str_contains($prompt, 'Login: As a user, I want to log in.'))
                ->andReturn(json_encode([
                    'recommendations' => [
                        ['feature' => 'Login', 'column' => 'must', 'reason' => 'Core to the product.'],
                    ],
                ]));
        });

        $response = $this->actingAs($user)->postJson('/api/ai/mvp-recommendation', [
            'module_id' => $mvpId,
            'features' => ['Login'],
            'locale' => 'en',
        ]);

        $response->assertOk()->assertJsonPath('recommendations.0.column', 'must');
    }
}
