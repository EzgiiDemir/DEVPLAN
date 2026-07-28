<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_endpoints_are_throttled_after_twenty_requests_per_minute(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturn(json_encode(['stories' => []]));
        });

        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Throttle Test']);
        $moduleId = collect($project->json('modules'))->firstWhere('module_type', 'requirements')['id'];

        $payload = ['module_id' => $moduleId, 'features' => ['Login'], 'locale' => 'en'];

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/api/ai/user-stories', $payload)->assertOk();
        }

        $this->actingAs($user)->postJson('/api/ai/user-stories', $payload)->assertStatus(429);
    }

    public function test_login_attempts_are_throttled_after_ten_per_minute(): void
    {
        User::factory()->create(['email' => 'target@example.com']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'target@example.com', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'target@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
