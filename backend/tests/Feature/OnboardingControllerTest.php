<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_onboarding_marks_it_server_side(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $response = $this->actingAs($user)->postJson('/api/v1/onboarding/complete');

        $response->assertOk();
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_completing_onboarding_twice_does_not_move_the_original_timestamp(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);
        $this->actingAs($user)->postJson('/api/v1/onboarding/complete');
        $firstTimestamp = $user->fresh()->onboarding_completed_at;

        $this->actingAs($user)->postJson('/api/v1/onboarding/complete');

        $this->assertEquals($firstTimestamp, $user->fresh()->onboarding_completed_at);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/onboarding/complete')->assertUnauthorized();
    }
}
