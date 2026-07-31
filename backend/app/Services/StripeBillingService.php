<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The real payment system behind plan selection — SubscriptionController
 * used to let a user PATCH their own `plan` field directly, with nothing
 * verifying they'd actually paid for it. Upgrading to a paid plan now means
 * a Stripe Checkout Session; the plan itself is only ever changed here, in
 * response to a signature-verified webhook event, never from a client
 * request claiming "I paid."
 */
class StripeBillingService
{
    public function __construct(private ?StripeClient $client = null)
    {
        $secret = config('services.stripe.secret');
        $this->client ??= $secret ? new StripeClient($secret) : null;
    }

    public function isConfigured(): bool
    {
        return $this->client !== null
            && config('services.stripe.prices.pro')
            && config('services.stripe.prices.team');
    }

    /**
     * @return string the Stripe-hosted Checkout URL to redirect the browser to
     */
    public function createCheckoutSession(User $user, string $plan): string
    {
        $this->assertConfigured();

        $priceId = $this->priceIdForPlan($plan);
        $subscription = $this->subscriptionFor($user);
        $customerId = $this->ensureStripeCustomer($user, $subscription);

        try {
            $session = $this->client->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'success_url' => rtrim(config('app.frontend_url'), '/').'/settings?billing=success',
                'cancel_url' => rtrim(config('app.frontend_url'), '/').'/settings?billing=cancelled',
                // Read back in the webhook — the authoritative link between
                // "this Stripe subscription" and "this plan", since Stripe
                // itself has no concept of our plan names.
                'subscription_data' => ['metadata' => ['plan' => $plan, 'user_id' => (string) $user->id]],
                'metadata' => ['plan' => $plan, 'user_id' => (string) $user->id],
            ]);
        } catch (ApiErrorException $e) {
            throw $this->wrapStripeError($e);
        }

        return $session->url;
    }

    /**
     * Schedules cancellation at the end of the current billing period —
     * never an immediate cutoff (the user already paid for this period) and
     * never a silent local-only downgrade while Stripe keeps billing them.
     * The `plan` column itself only changes later, once the subscription
     * actually ends (customer.subscription.deleted webhook).
     */
    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        $this->assertConfigured();

        if (! $subscription->stripe_subscription_id) {
            throw new RuntimeException('No active paid subscription to cancel.');
        }

        try {
            $this->client->subscriptions->update($subscription->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->wrapStripeError($e);
        }

        $subscription->update(['cancel_at_period_end' => true]);

        Log::info('billing.cancellation_scheduled', ['subscription_id' => $subscription->stripe_subscription_id]);
    }

    /**
     * Used by account deletion — unlike cancelAtPeriodEnd(), there's no
     * later point at which "period end" will arrive for a user whose
     * account no longer exists, so this cancels on Stripe's side right now
     * rather than leaving a live subscription still billing a deleted
     * account's card.
     */
    public function cancelImmediately(Subscription $subscription): void
    {
        $this->assertConfigured();

        if (! $subscription->stripe_subscription_id) {
            return;
        }

        try {
            $this->client->subscriptions->cancel($subscription->stripe_subscription_id);
        } catch (ApiErrorException $e) {
            throw $this->wrapStripeError($e);
        }

        Log::info('billing.cancelled_immediately', ['subscription_id' => $subscription->stripe_subscription_id]);
    }

    /**
     * @return string the Stripe-hosted Billing Portal URL (manage payment
     *                method, view invoices, cancel) — Stripe's own UI, not
     *                one this app has to build and keep PCI-scope-aware.
     */
    public function createPortalSession(User $user): string
    {
        $this->assertConfigured();

        $subscription = $this->subscriptionFor($user);
        if (! $subscription->stripe_customer_id) {
            throw new RuntimeException('No billing account exists yet — subscribe to a paid plan first.');
        }

        try {
            $portalSession = $this->client->billingPortal->sessions->create([
                'customer' => $subscription->stripe_customer_id,
                'return_url' => rtrim(config('app.frontend_url'), '/').'/settings',
            ]);
        } catch (ApiErrorException $e) {
            throw $this->wrapStripeError($e);
        }

        return $portalSession->url;
    }

    /**
     * Verifies the request genuinely came from Stripe (HMAC signature over
     * the raw body, using STRIPE_WEBHOOK_SECRET) before trusting anything in
     * it — this is the one and only place a subscription's `plan`/`status`
     * actually changes. Throws SignatureVerificationException on a bad
     * signature; the controller turns that into a 400, never a 200 (a
     * replayed/forged request must never look successful to a retrying
     * attacker or a confused monitoring dashboard).
     */
    public function handleWebhook(string $payload, string $signatureHeader): void
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        if (! $webhookSecret) {
            // Fail closed: with no secret configured, there is no way to
            // distinguish a real Stripe event from a forged one, so nothing
            // is trusted rather than skipping verification.
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $event = Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);

        Log::info('billing.webhook_received', ['type' => $event->type, 'id' => $event->id]);

        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event->data->object),
            'customer.subscription.updated' => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($event->data->object),
            default => null,
        };
    }

    private function onCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $userId = $session->metadata['user_id'] ?? null;
        $plan = $session->metadata['plan'] ?? null;
        if (! $userId || ! $plan) {
            Log::warning('billing.webhook_missing_metadata', ['session_id' => $session->id]);

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $subscription = $this->subscriptionFor($user);
        $subscription->update([
            'plan' => $plan,
            'stripe_customer_id' => is_string($session->customer) ? $session->customer : $session->customer?->id,
            'stripe_subscription_id' => is_string($session->subscription) ? $session->subscription : $session->subscription?->id,
            'status' => 'active',
        ]);

        Log::info('billing.subscription_activated', ['user_id' => $user->id, 'plan' => $plan]);
    }

    private function onSubscriptionUpdated(\Stripe\Subscription $stripeSubscription): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        if (! $subscription) {
            return;
        }

        $subscription->update([
            'status' => $stripeSubscription->status,
            'current_period_end' => $stripeSubscription->current_period_end
                ? \Illuminate\Support\Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : null,
            'cancel_at_period_end' => (bool) $stripeSubscription->cancel_at_period_end,
        ]);

        Log::info('billing.subscription_status_changed', [
            'subscription_id' => $stripeSubscription->id,
            'status' => $stripeSubscription->status,
        ]);
    }

    private function onSubscriptionDeleted(\Stripe\Subscription $stripeSubscription): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        if (! $subscription) {
            return;
        }

        // A cancelled/expired paid subscription drops the user back to the
        // free plan rather than leaving them on a paid plan with no active
        // billing behind it.
        $subscription->update([
            'plan' => 'free',
            'status' => 'canceled',
            'stripe_subscription_id' => null,
            'cancel_at_period_end' => false,
        ]);

        Log::info('billing.subscription_cancelled', ['subscription_id' => $stripeSubscription->id]);
    }

    private function ensureStripeCustomer(User $user, Subscription $subscription): string
    {
        if ($subscription->stripe_customer_id) {
            return $subscription->stripe_customer_id;
        }

        try {
            $customer = $this->client->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => ['user_id' => (string) $user->id],
            ]);
        } catch (ApiErrorException $e) {
            throw $this->wrapStripeError($e);
        }

        $subscription->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    private function subscriptionFor(User $user): Subscription
    {
        return $user->subscriptions()->firstOrCreate([], ['plan' => 'free']);
    }

    private function priceIdForPlan(string $plan): string
    {
        $priceId = config("services.stripe.prices.{$plan}");
        if (! $priceId) {
            throw new RuntimeException("No Stripe price configured for plan \"{$plan}\".");
        }

        return $priceId;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Billing is not configured on this server yet.');
        }
    }

    /**
     * Stripe API errors (a bad/expired card, a subscription id that no
     * longer exists, Stripe itself being unreachable) are real, expected
     * outcomes of talking to an external service — turned into the same
     * RuntimeException → 503 path as "billing isn't configured" rather than
     * propagating as an uncaught SDK exception and surfacing as a generic
     * 500.
     */
    private function wrapStripeError(ApiErrorException $e): RuntimeException
    {
        Log::error('billing.stripe_api_error', ['error' => $e->getMessage()]);

        return new RuntimeException('Billing request failed: '.$e->getMessage());
    }
}
