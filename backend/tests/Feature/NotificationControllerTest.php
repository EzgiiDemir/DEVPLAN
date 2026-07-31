<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function sendMentionTo(User $user): void
    {
        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'developer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Discussion Project']);
        $comment = $project->comments()->create([
            'user_id' => $owner->id,
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'mention test',
        ]);

        $user->notify(new MentionNotification($comment, $owner, $project));
    }

    public function test_a_user_can_list_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $this->sendMentionTo($user);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame('mention', $response->json('0.data.type'));
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->sendMentionTo($otherUser);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_unread_count_reflects_unread_notifications(): void
    {
        $user = User::factory()->create();
        $this->sendMentionTo($user);
        $this->sendMentionTo($user);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()->assertJson(['count' => 2]);
    }

    public function test_marking_a_single_notification_as_read_removes_it_from_the_unread_count(): void
    {
        $user = User::factory()->create();
        $this->sendMentionTo($user);
        $this->sendMentionTo($user);
        $notificationId = $user->notifications()->first()->id;

        $this->actingAs($user)->postJson("/api/v1/notifications/{$notificationId}/read")->assertOk();

        $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')
            ->assertJson(['count' => 1]);
        $this->assertNotNull($user->notifications()->find($notificationId)->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->sendMentionTo($otherUser);
        $notificationId = $otherUser->notifications()->first()->id;

        $this->actingAs($user)->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();
    }

    public function test_mark_all_as_read_clears_the_unread_count(): void
    {
        $user = User::factory()->create();
        $this->sendMentionTo($user);
        $this->sendMentionTo($user);
        $this->sendMentionTo($user);

        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertNoContent();

        $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')
            ->assertJson(['count' => 0]);
    }
}
