<?php

namespace App\Http\Controllers;

use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;

/**
 * Stripe calls this directly (not the SPA) — no session, no Sanctum auth,
 * no CSRF token, just a raw signed POST. Trust comes entirely from the
 * signature check inside StripeBillingService::handleWebhook(), never from
 * anything else about the request.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private StripeBillingService $billing) {}

    public function handle(Request $request)
    {
        $signature = $request->header('Stripe-Signature', '');

        try {
            $this->billing->handleWebhook($request->getContent(), $signature);
        } catch (SignatureVerificationException $e) {
            Log::warning('billing.webhook_signature_invalid', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (RuntimeException $e) {
            Log::error('billing.webhook_error', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['received' => true]);
    }
}
