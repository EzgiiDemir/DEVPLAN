<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_update_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patchJson('/api/profile', ['name' => 'New Name']);

        $response->assertOk()->assertJsonPath('name', 'New Name');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertNoContent();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_rejects_password_change_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'totally-wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_rejects_password_change_when_confirmation_does_not_match(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
    }
}
