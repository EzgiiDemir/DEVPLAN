<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_sends_a_real_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertCreated();

        Notification::assertSentTo(
            User::where('email', 'ada@example.com')->firstOrFail(),
            VerifyEmailNotification::class,
        );
    }

    public function test_a_genuinely_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
        $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->getJson($path);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'wrong-hash-entirely'],
        );
        $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->getJson($path);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
        $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->getJson($path);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->getJson("/api/v1/email/verify/{$user->id}/".sha1($user->email));

        $response->assertForbidden();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/email/resend')->assertUnauthorized();
    }

    public function test_authenticated_resend_sends_a_new_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->postJson('/api/v1/email/resend')->assertOk();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_is_a_no_op_for_an_already_verified_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/email/resend')->assertOk();

        Notification::assertNothingSent();
    }
}
