<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Real TOTP (RFC 6238) via pragmarx/google2fa — no external service, no
 * hand-rolled crypto. A user isn't "MFA enabled" until confirm() succeeds
 * with a real code generated from the secret (proves they actually saved it
 * in an authenticator app before it starts being required at login).
 */
class TwoFactorService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /**
     * Generates a new secret and stores it un-confirmed — login isn't
     * gated on it until confirm() succeeds.
     */
    public function generateSecret(User $user): array
    {
        $secret = $this->engine->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->engine->getQRCodeUrl('DevPlan', $user->email, $secret),
        ];
    }

    /**
     * @return array<int, string>|null the plaintext recovery codes (shown
     *   once, never retrievable again — only their hashes are stored), or
     *   null if the code didn't verify against the pending secret.
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! $this->engine->verifyKey($user->two_factor_secret, $code)) {
            return null;
        }

        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Accepts either a real TOTP code or a one-time recovery code (consumed
     * on use — removed from the stored list so it can't be replayed).
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        if ($this->engine->verifyKey($user->two_factor_secret, $code)) {
            return true;
        }

        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        foreach ($recoveryCodes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($recoveryCodes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($recoveryCodes)])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
