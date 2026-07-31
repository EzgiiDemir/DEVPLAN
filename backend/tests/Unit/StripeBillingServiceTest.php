<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Exception\SignatureVerificationException;
use Tests\TestCase;

/**
 * Runs against the real Stripe test-mode API (a real sk_test_ key is
 * configured for this project) rather than mocking the Stripe SDK — the
 * same "real over fixture" discipline used throughout this codebase's other
 * tests. Webhook payloads are signed with the exact HMAC scheme Stripe's own
 * SDK verifies against (see signStripePayload()), so signature verification
 * itself is exercised for real, not bypassed.
 */
class StripeBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function signStripePayload(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('services.stripe.secret')) {
            $this->markTestSkipped('STRIPE_SECRET is not configured in this environment.');
        }
    }

    public function test_billing_is_configured_when_keys_and_prices_are_present(): void
    {
        $this->assertTrue(app(StripeBillingService::class)->isConfigured());
    }

    public function test_creating_a_checkout_session_returns_a_real_stripe_hosted_url(): void
    {
        $user = User::factory()->create();

        $url = app(StripeBillingService::class)->createCheckoutSession($user, 'pro');

        $this->assertStringStartsWith('https://checkout.stripe.com/', $url);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id]);
        $this->assertNotNull($user->subscriptions()->first()->stripe_customer_id);
    }

    public function test_a_forged_webhook_signature_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_fake', 'type' => 'checkout.session.completed', 'data' => ['object' => []]]);
        $badHeader = $this->signStripePayload($payload, 'wrong-secret');

        $this->expectException(SignatureVerificationException::class);

        app(StripeBillingService::class)->handleWebhook($payload, $badHeader);
    }

    public function test_checkout_completed_webhook_activates_the_plan(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create(['plan' => 'free']);

        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'object' => 'checkout.session',
                    'id' => 'cs_test_fake',
                    'customer' => 'cus_test_fake',
                    'subscription' => 'sub_test_fake',
                    'metadata' => ['user_id' => (string) $user->id, 'plan' => 'pro'],
                ],
            ],
        ]);
        $header = $this->signStripePayload($payload, config('services.stripe.webhook_secret'));

        app(StripeBillingService::class)->handleWebhook($payload, $header);

        $subscription->refresh();
        $this->assertSame('pro', $subscription->plan);
        $this->assertSame('cus_test_fake', $subscription->stripe_customer_id);
        $this->assertSame('sub_test_fake', $subscription->stripe_subscription_id);
        $this->assertSame('active', $subscription->status);
    }

    public function test_subscription_deleted_webhook_downgrades_to_free(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'plan' => 'pro',
            'stripe_subscription_id' => 'sub_test_to_delete',
            'status' => 'active',
        ]);

        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'object' => 'subscription',
                    'id' => 'sub_test_to_delete',
                    'status' => 'canceled',
                ],
            ],
        ]);
        $header = $this->signStripePayload($payload, config('services.stripe.webhook_secret'));

        app(StripeBillingService::class)->handleWebhook($payload, $header);

        $subscription->refresh();
        $this->assertSame('free', $subscription->plan);
        $this->assertSame('canceled', $subscription->status);
        $this->assertNull($subscription->stripe_subscription_id);
    }

    public function test_subscription_updated_webhook_syncs_status_and_period_end(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'plan' => 'pro',
            'stripe_subscription_id' => 'sub_test_to_update',
            'status' => 'active',
        ]);
        $periodEnd = time() + 86400 * 30;

        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'object' => 'subscription',
                    'id' => 'sub_test_to_update',
                    'status' => 'past_due',
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => true,
                ],
            ],
        ]);
        $header = $this->signStripePayload($payload, config('services.stripe.webhook_secret'));

        app(StripeBillingService::class)->handleWebhook($payload, $header);

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame($periodEnd, $subscription->current_period_end->timestamp);
    }

    public function test_an_unconfigured_webhook_secret_fails_closed(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->expectException(\RuntimeException::class);

        app(StripeBillingService::class)->handleWebhook('{}', 't=1,v1=x');
    }
}
