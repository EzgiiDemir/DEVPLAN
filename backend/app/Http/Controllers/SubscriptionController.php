<?php

namespace App\Http\Controllers;

use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionController extends Controller
{
    private const PAID_PLANS = ['pro', 'team'];

    private const PLANS = ['free', 'pro', 'team'];

    public function __construct(private StripeBillingService $billing) {}

    public function show(Request $request)
    {
        $subscription = $request->user()->subscriptions()->firstOrCreate([], ['plan' => 'free']);

        return response()->json($subscription);
    }

    /**
     * A real payment gate replaces the old self-service plan flip:
     *  - "free" either sets a brand-new subscription to free directly, or —
     *    if the user currently has an active paid Stripe subscription —
     *    schedules cancellation at the end of the current billing period
     *    (never a silent local downgrade while Stripe keeps billing them,
     *    and never an instant loss of what they already paid for).
     *  - "pro"/"team" returns a Stripe Checkout URL instead of changing the
     *    plan directly — the plan itself only ever changes once Stripe
     *    confirms payment via a signature-verified webhook event.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'in:'.implode(',', self::PLANS)],
        ]);

        $subscription = $request->user()->subscriptions()->firstOrCreate([], ['plan' => 'free']);

        if (in_array($data['plan'], self::PAID_PLANS, true)) {
            try {
                $checkoutUrl = $this->billing->createCheckoutSession($request->user(), $data['plan']);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            return response()->json(['checkout_url' => $checkoutUrl]);
        }

        // plan === 'free'
        if ($subscription->stripe_subscription_id) {
            try {
                $this->billing->cancelAtPeriodEnd($subscription);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            return response()->json($subscription->fresh());
        }

        $subscription->update(['plan' => 'free']);

        return response()->json($subscription->fresh());
    }

    /**
     * A Stripe-hosted Billing Portal session — payment method updates,
     * invoice history, and cancellation all happen on Stripe's own PCI-
     * compliant UI rather than a page this app has to build and secure.
     */
    public function billingPortal(Request $request)
    {
        try {
            $portalUrl = $this->billing->createPortalSession($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['portal_url' => $portalUrl]);
    }
}
