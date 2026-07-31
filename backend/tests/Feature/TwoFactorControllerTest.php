<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_totp_code_confirms_and_enables_mfa_with_recovery_codes(): void
    {
        $user = User::factory()->create();

        $generate = $this->actingAs($user)->postJson('/api/v1/security/two-factor/generate');
        $generate->assertOk();
        $secret = $generate->json('secret');
        $this->assertNotEmpty($secret);

        // The same RFC 6238 algorithm a real authenticator app runs —
        // computed independently here from the real secret the endpoint
        // just issued, not stubbed.
        $code = (new Google2FA)->getCurrentOtp($secret);

        $confirm = $this->actingAs($user)->postJson('/api/v1/security/two-factor/confirm', ['code' => $code]);
        $confirm->assertCreated();
        $this->assertCount(8, $confirm->json('recovery_codes'));

        $this->assertTrue($user->fresh()->hasMfaEnabled());
        $this->actingAs($user)->getJson('/api/v1/security/two-factor')->assertOk()->assertJsonPath('enabled', true);
    }

    public function test_a_wrong_code_does_not_confirm_mfa(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/security/two-factor/generate');

        $this->actingAs($user)
            ->postJson('/api/v1/security/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($user->fresh()->hasMfaEnabled());
    }

    public function test_login_requires_a_real_mfa_code_once_enabled(): void
    {
        // Set up MFA through a real login session — actingAs() binds the
        // guard directly in-memory and doesn't behave like a real cookie
        // session, which would make the "still logged out" assertion below
        // meaningless. Going through the real /login flow first is both
        // more realistic and avoids that entirely.
        User::factory()->create(['email' => 'mfa@example.com', 'password' => bcrypt('password123')]);
        $this->postJson('/api/v1/login', ['email' => 'mfa@example.com', 'password' => 'password123'])->assertOk();

        $generate = $this->postJson('/api/v1/security/two-factor/generate');
        $secret = $generate->json('secret');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/v1/security/two-factor/confirm', ['code' => $code])->assertCreated();

        $this->postJson('/api/v1/logout');
        // Sanctum's guard caches its resolved user for the guard instance's
        // lifetime — fine in real requests (one guard resolution per real
        // HTTP request), but sequential test calls share one container, so
        // the cache has to be dropped explicitly to observe a real logout.
        Auth::forgetGuards();

        // A fresh login now must NOT fully authenticate yet.
        $login = $this->postJson('/api/v1/login', ['email' => 'mfa@example.com', 'password' => 'password123']);
        $login->assertOk()->assertJsonPath('requires_mfa', true);
        $this->getJson('/api/v1/user')->assertStatus(401);

        // Wrong MFA code is rejected.
        $this->postJson('/api/v1/login/mfa', ['code' => '000000'])->assertStatus(422);

        // Real MFA code completes the login.
        $freshCode = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/v1/login/mfa', ['code' => $freshCode])->assertOk()->assertJsonPath('email', 'mfa@example.com');
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('email', 'mfa@example.com');
    }

    public function test_a_recovery_code_can_be_used_once_and_then_is_rejected(): void
    {
        User::factory()->create(['email' => 'recovery@example.com', 'password' => bcrypt('password123')]);
        $this->postJson('/api/v1/login', ['email' => 'recovery@example.com', 'password' => 'password123'])->assertOk();

        $generate = $this->postJson('/api/v1/security/two-factor/generate');
        $secret = $generate->json('secret');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $confirm = $this->postJson('/api/v1/security/two-factor/confirm', ['code' => $code]);
        $recoveryCode = $confirm->json('recovery_codes')[0];

        $this->postJson('/api/v1/logout');
        Auth::forgetGuards();

        $this->postJson('/api/v1/login', ['email' => 'recovery@example.com', 'password' => 'password123']);
        $this->postJson('/api/v1/login/mfa', ['code' => $recoveryCode])->assertOk();

        // Log out and try to reuse the SAME recovery code — must fail now.
        $this->postJson('/api/v1/logout');
        Auth::forgetGuards();
        $this->postJson('/api/v1/login', ['email' => 'recovery@example.com', 'password' => 'password123']);
        $this->postJson('/api/v1/login/mfa', ['code' => $recoveryCode])->assertStatus(422);
    }

    public function test_disabling_mfa_removes_the_login_challenge(): void
    {
        $user = User::factory()->create(['email' => 'disable@example.com', 'password' => bcrypt('password123')]);
        $generate = $this->actingAs($user)->postJson('/api/v1/security/two-factor/generate');
        $secret = $generate->json('secret');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->actingAs($user)->postJson('/api/v1/security/two-factor/confirm', ['code' => $code]);

        $this->actingAs($user)->deleteJson('/api/v1/security/two-factor')->assertNoContent();
        $this->assertFalse($user->fresh()->hasMfaEnabled());

        $login = $this->postJson('/api/v1/login', ['email' => 'disable@example.com', 'password' => 'password123']);
        $login->assertOk()->assertJsonPath('email', 'disable@example.com');
    }
}
