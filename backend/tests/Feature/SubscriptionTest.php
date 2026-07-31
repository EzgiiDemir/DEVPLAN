<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the real-payment-gated /subscription endpoint (Stripe integration
 * added on top of what used to be a pure self-service plan flip — see
 * SubscriptionController/StripeBillingService). Runs against the real
 * Stripe test-mode API configured for this project; tests that would need a
 * real Stripe subscription already active (cancellation, portal) are
 * skipped when STRIPE_SECRET isn't configured rather than mocked, since a
 * mock can't prove the real Checkout/webhook wiring actually works.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_is_lazily_created_as_free_on_first_view(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/subscription');

        $response->assertOk()->assertJsonPath('plan', 'free');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'free']);
    }

    public function test_selecting_a_paid_plan_returns_a_checkout_url_instead_of_flipping_the_plan_directly(): void
    {
        if (! config('services.stripe.secret')) {
            $this->markTestSkipped('STRIPE_SECRET is not configured in this environment.');
        }

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/subscription');

        $response = $this->actingAs($user)->patchJson('/api/v1/subscription', ['plan' => 'pro']);

        $response->assertOk();
        $this->assertStringStartsWith('https://checkout.stripe.com/', $response->json('checkout_url'));
        // The plan itself must NOT have changed yet — only a real,
        // signature-verified webhook does that.
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'free']);
    }

    public function test_rejects_an_invalid_plan_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/v1/subscription', ['plan' => 'ultra-mega-plan']);

        $response->assertStatus(422);
    }

    public function test_selecting_free_with_no_prior_paid_subscription_downgrades_instantly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/subscription');

        $response = $this->actingAs($user)->patchJson('/api/v1/subscription', ['plan' => 'free']);

        $response->assertOk()->assertJsonPath('plan', 'free');
    }

    public function test_selecting_free_with_an_active_paid_subscription_schedules_cancellation_instead_of_an_instant_downgrade(): void
    {
        if (! config('services.stripe.secret')) {
            $this->markTestSkipped('STRIPE_SECRET is not configured in this environment.');
        }

        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'plan' => 'pro',
            // A real subscription ID would be needed for the actual Stripe
            // API call to succeed against a live account; this test only
            // needs to prove the endpoint attempts a real cancellation
            // rather than silently flipping the local plan, so a
            // non-existent id correctly surfaces as a 503 from Stripe
            // rejecting the update — not a 200 with a falsely "cancelled"
            // local row.
            'stripe_subscription_id' => 'sub_nonexistent_for_test',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->patchJson('/api/v1/subscription', ['plan' => 'free']);

        $response->assertStatus(503);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'plan' => 'pro', 'cancel_at_period_end' => false]);
    }

    public function test_each_user_has_their_own_subscription(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->getJson('/api/v1/subscription');

        $responseB = $this->actingAs($userB)->getJson('/api/v1/subscription');

        $responseB->assertOk()->assertJsonPath('plan', 'free');
    }

    public function test_billing_portal_requires_an_existing_stripe_customer(): void
    {
        if (! config('services.stripe.secret')) {
            $this->markTestSkipped('STRIPE_SECRET is not configured in this environment.');
        }

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/subscription');

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/billing-portal');

        $response->assertStatus(503);
    }
}
