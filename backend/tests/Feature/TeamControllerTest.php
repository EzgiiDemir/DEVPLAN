<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_a_personal_team_and_it_appears_in_the_index(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ezgi',
            'email' => 'ezgi@example.com',
            'password' => 'password123',
        ]);
        $response->assertCreated();

        $user = User::where('email', 'ezgi@example.com')->firstOrFail();

        $teams = $this->actingAs($user)->getJson('/api/v1/teams');
        $teams->assertOk()->assertJsonCount(1);
        $this->assertTrue($teams->json()[0]['personal']);
        $this->assertSame('owner', $teams->json()[0]['role']);
    }

    public function test_creating_a_real_team_makes_the_creator_its_owner(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/teams', ['name' => 'Acme Inc']);
        $response->assertCreated();

        $team = Team::findOrFail($response->json('id'));
        $this->assertSame('owner', TeamMember::where('team_id', $team->id)->where('user_id', $user->id)->value('role'));
        $this->assertFalse((bool) $team->personal);
    }

    public function test_full_invite_accept_role_change_remove_lifecycle(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $invite = $this->actingAs($owner)->postJson("/api/v1/teams/{$team->id}/members/invite", [
            'email' => 'invitee@example.com',
            'role' => 'developer',
        ]);
        $invite->assertCreated();
        Mail::assertSent(TeamInvitationMail::class);
        $token = $invite->json('token');

        $preview = $this->getJson("/api/v1/invitations/{$token}");
        $preview->assertOk()->assertJsonPath('team_name', 'Acme Inc')->assertJsonPath('accepted', false);

        $accept = $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept");
        $accept->assertCreated();
        $memberId = $accept->json('id');
        $this->assertSame('developer', TeamMember::find($memberId)->role);

        // Re-accepting an already-used invitation must fail (410 Gone).
        $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);

        $roleChange = $this->actingAs($owner)->patchJson("/api/v1/teams/{$team->id}/members/{$memberId}", ['role' => 'admin']);
        $roleChange->assertOk()->assertJsonPath('role', 'admin');

        $this->actingAs($owner)->deleteJson("/api/v1/teams/{$team->id}/members/{$memberId}")->assertNoContent();
        $this->assertDatabaseMissing('team_members', ['id' => $memberId]);
    }

    public function test_accepting_an_invitation_sent_to_a_different_email_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $invitation = app(TeamService::class)->invite($team, 'target@example.com', 'developer', $owner);

        $this->actingAs($wrongUser)
            ->postJson("/api/v1/invitations/{$invitation->token}/accept")
            ->assertForbidden();
    }

    public function test_a_developer_cannot_invite_members_but_an_admin_can(): void
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $admin = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $developer->id, 'role' => 'developer']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($developer)
            ->postJson("/api/v1/teams/{$team->id}/members/invite", ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/v1/teams/{$team->id}/members/invite", ['email' => 'y@example.com', 'role' => 'viewer'])
            ->assertCreated();
    }

    /**
     * Covers the notification system: inviting an email address that
     * already belongs to a real DevPlan account now sends them an in-app
     * notification too, not just the (easy-to-miss) invitation email.
     */
    public function test_inviting_an_existing_user_sends_them_an_in_app_notification(): void
    {
        Notification::fake();
        Mail::fake();

        $owner = User::factory()->create();
        $existingUser = User::factory()->create(['email' => 'already-here@example.com']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->postJson("/api/v1/teams/{$team->id}/members/invite", [
            'email' => 'already-here@example.com',
            'role' => 'developer',
        ])->assertCreated();

        Notification::assertSentTo($existingUser, TeamInvitationNotification::class);
    }

    public function test_inviting_an_email_with_no_account_yet_sends_no_in_app_notification(): void
    {
        Notification::fake();
        Mail::fake();

        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->postJson("/api/v1/teams/{$team->id}/members/invite", [
            'email' => 'nobody-yet@example.com',
            'role' => 'developer',
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_removing_the_last_owner_is_blocked(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        $ownerMembership = TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/teams/{$team->id}/members/{$ownerMembership->id}")
            ->assertStatus(422);
    }

    public function test_personal_team_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $team = app(TeamService::class)->ensurePersonalTeam($user);

        $this->actingAs($user)->deleteJson("/api/v1/teams/{$team->id}")->assertStatus(422);
    }

    public function test_team_with_projects_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
        $user->projects()->create(['team_id' => $team->id, 'title' => 'A Project']);

        $this->actingAs($user)->deleteJson("/api/v1/teams/{$team->id}")->assertStatus(422);
    }
}
