<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the real HTTP route Stripe itself calls — no Sanctum session,
 * no CSRF token, just a raw signed POST — as opposed to
 * StripeBillingServiceTest, which calls handleWebhook() directly.
 */
class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function signStripePayload(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('services.stripe.webhook_secret')) {
            $this->markTestSkipped('STRIPE_WEBHOOK_SECRET is not configured in this environment.');
        }
    }

    /**
     * Deliberately no actingAs() anywhere in this file — Stripe's servers
     * never authenticate as a user, and this proves the route genuinely
     * works unauthenticated (a 401 here would fail every test below, not
     * just a dedicated one).
     */
    public function test_a_validly_signed_checkout_completed_event_activates_the_plan_end_to_end(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['plan' => 'free']);

        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'object' => 'checkout.session',
                    'id' => 'cs_test_fake',
                    'customer' => 'cus_test_fake',
                    'subscription' => 'sub_test_fake',
                    'metadata' => ['user_id' => (string) $user->id, 'plan' => 'team'],
                ],
            ],
        ]);
        $header = $this->signStripePayload($payload, config('services.stripe.webhook_secret'));

        $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'team', 'status' => 'active']);
    }

    public function test_a_forged_signature_is_rejected_with_a_400_not_a_500(): void
    {
        $payload = json_encode(['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => []]]);
        $header = $this->signStripePayload($payload, 'totally-wrong-secret');

        $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);

        $response->assertStatus(400);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => []]]);

        $response = $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(400);
    }
}
