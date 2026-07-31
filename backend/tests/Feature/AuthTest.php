<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_register(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ezgi Demir',
            'email' => 'ezgi@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()->assertJsonPath('email', 'ezgi@example.com');
        $this->assertDatabaseHas('users', ['email' => 'ezgi@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register']);
    }

    public function test_cannot_register_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('email', 'login@example.com');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/logout');

        $response->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.logout']);
    }

    public function test_authenticated_user_endpoint_returns_current_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/user');

        $response->assertOk()->assertJsonPath('id', $user->id);
    }
}
