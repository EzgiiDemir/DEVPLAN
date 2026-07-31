<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user, string $title = 'My Personal Project'): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => $title]);

        return Project::findOrFail($response->json('id'));
    }

    public function test_deleting_the_account_removes_the_user_their_session_and_their_personal_project(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $team = $project->team;

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_a_wrong_current_password_is_rejected_and_the_account_survives(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'not-the-real-password']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_an_oauth_only_user_can_delete_their_account_without_a_password(): void
    {
        $user = User::factory()->create(['oauth_provider' => 'github', 'oauth_id' => '12345']);

        $response = $this->actingAs($user)->deleteJson('/api/v1/account');

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_deletion_is_blocked_when_the_user_owns_a_project_inside_a_shared_team(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
        $otherOwner = User::factory()->create();
        TeamMember::create(['team_id' => $team->id, 'user_id' => $otherOwner->id, 'role' => 'owner']);
        $project = $user->projects()->create(['team_id' => $team->id, 'title' => 'Shared Team Project']);

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Shared Team Project', $response->json('message'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_deletion_is_blocked_when_the_user_is_the_sole_owner_of_a_shared_team(): void
    {
        $user = User::factory()->create();
        $developer = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $developer->id, 'role' => 'developer']);

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Acme Inc', $response->json('message'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_a_non_sole_owner_can_delete_their_account_and_the_shared_team_survives_untouched(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $otherOwner->id, 'role' => 'owner']);
        $project = $otherOwner->projects()->create(['team_id' => $team->id, 'title' => 'Owned By Someone Else']);

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $user->id]);
    }

    public function test_a_stripe_cancellation_failure_does_not_block_the_gdpr_deletion(): void
    {
        if (! config('services.stripe.secret')) {
            $this->markTestSkipped('STRIPE_SECRET is not configured in this environment.');
        }

        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan' => 'pro',
            // A real API call against this nonexistent id will fail — the
            // point of this test is proving that failure doesn't stop the
            // account from actually being deleted.
            'stripe_subscription_id' => 'sub_nonexistent_for_test',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_project_data_belonging_to_the_deleted_user_within_their_personal_team_is_fully_gone(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'Gone Project']);
        $module = $project->modules()->create(['module_type' => 'idea', 'order_index' => 0]);
        $module->items()->create(['item_type' => 'idea', 'content' => ['pitch' => 'secret idea']]);
        $project->tasks()->create(['title' => 'Gone Task', 'status' => 'todo']);

        $this->actingAs($user)->deleteJson('/api/v1/account', ['current_password' => 'password']);

        $this->assertDatabaseMissing('modules', ['id' => $module->id]);
        $this->assertDatabaseMissing('module_items', ['module_id' => $module->id]);
        $this->assertDatabaseMissing('tasks', ['project_id' => $project->id]);
    }
}
