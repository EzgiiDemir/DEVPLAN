<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_reset_link_sends_a_real_notification_with_a_real_broker_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        $response->assertOk();
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            return Password::tokenExists($user, $notification->token);
        });
    }

    /**
     * The response must not reveal whether the email actually exists —
     * otherwise this endpoint becomes an email-enumeration oracle.
     */
    public function test_requesting_a_reset_link_for_an_unknown_email_gives_the_same_generic_response(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertOk();
        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'fake-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => time(),
        ]);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password-456', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'fake-session-id']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.password_reset']);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_requires_password_confirmation_to_match(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
    }
}
