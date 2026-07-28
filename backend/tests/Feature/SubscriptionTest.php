<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_is_lazily_created_as_free_on_first_view(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/subscription');

        $response->assertOk()->assertJsonPath('plan', 'free');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'free']);
    }

    public function test_can_upgrade_plan(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/subscription');

        $response = $this->actingAs($user)->patchJson('/api/subscription', ['plan' => 'pro']);

        $response->assertOk()->assertJsonPath('plan', 'pro');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'pro']);
    }

    public function test_rejects_an_invalid_plan_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/subscription', ['plan' => 'ultra-mega-plan']);

        $response->assertStatus(422);
    }

    public function test_each_user_has_their_own_subscription(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->patchJson('/api/subscription', ['plan' => 'team']);

        $responseB = $this->actingAs($userB)->getJson('/api/subscription');

        $responseB->assertOk()->assertJsonPath('plan', 'free');
    }
}
