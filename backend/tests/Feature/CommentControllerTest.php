<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mentioning_a_real_team_member_by_name_creates_a_mention_row(): void
    {
        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $teammate = User::factory()->create(['name' => 'Ada Lovelace']);
        $outsider = User::factory()->create(['name' => 'Not On Team']);

        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'role' => 'developer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Discussion Project']);

        $response = $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/comments", [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'Hey @Ada Lovelace and @Not On Team, can you take a look?',
        ]);

        $response->assertCreated();
        $mentions = $response->json('mentions');
        $this->assertCount(1, $mentions);
        $this->assertSame($teammate->id, $mentions[0]['mentioned_user_id']);

        $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $teammate->id]);
        $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $outsider->id]);
    }

    /**
     * Covers the notification system: a mention now actually notifies the
     * mentioned teammate, not just recording a Mention row nobody sees.
     */
    public function test_mentioning_a_teammate_sends_them_a_real_notification(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $teammate = User::factory()->create(['name' => 'Ada Lovelace']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'role' => 'developer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Discussion Project']);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/comments", [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'Hey @Ada Lovelace, can you take a look?',
        ])->assertCreated();

        Notification::assertSentTo($teammate, MentionNotification::class);
        Notification::assertNotSentTo($owner, MentionNotification::class);
    }

    public function test_mentioning_yourself_does_not_send_a_notification(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Discussion Project']);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/comments", [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'Note to self, @Ezgi Demir.',
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_a_viewer_can_read_the_thread_but_not_post(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Discussion Project']);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/comments", [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'First comment',
        ])->assertCreated();

        $this->actingAs($viewer)
            ->getJson("/api/v1/projects/{$project->id}/comments?commentable_type=project&commentable_id={$project->id}")
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($viewer)->postJson("/api/v1/projects/{$project->id}/comments", [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'I should not be able to post this',
        ])->assertForbidden();
    }

    public function test_a_comment_on_a_feature_request_from_a_different_project_is_rejected(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $projectA = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Project A']);
        $projectB = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Project B']);

        $featureRequest = $projectB->featureRequests()->create(['user_id' => $owner->id, 'prompt' => 'add a thing']);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$projectA->id}/comments", [
            'commentable_type' => 'feature_request',
            'commentable_id' => $featureRequest->id,
            'body' => 'cross-project comment attempt',
        ])->assertNotFound();
    }
}
