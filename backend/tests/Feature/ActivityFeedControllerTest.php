<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityFeedControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_merges_real_entries_from_multiple_tables_newest_first(): void
    {
        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Feed Project']);

        $project->featureRequests()->create(['user_id' => $owner->id, 'prompt' => 'add a login page', 'status' => 'applied']);
        $project->tasks()->create(['title' => 'Write docs', 'status' => 'todo']);
        $project->comments()->create(['project_id' => $project->id, 'commentable_type' => 'project', 'commentable_id' => $project->id, 'user_id' => $owner->id, 'body' => 'Looks good']);

        $response = $this->actingAs($owner)->getJson("/api/v1/projects/{$project->id}/activity");

        $response->assertOk();
        $types = collect($response->json())->pluck('type')->all();
        $this->assertContains('feature_request', $types);
        $this->assertContains('task', $types);
        $this->assertContains('comment', $types);

        $timestamps = collect($response->json())->pluck('created_at')->map(fn ($t) => strtotime($t));
        $this->assertSame($timestamps->sortDesc()->values()->all(), $timestamps->values()->all());
    }

    public function test_a_non_member_cannot_read_the_activity_feed(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Feed Project']);

        $this->actingAs($outsider)->getJson("/api/v1/projects/{$project->id}/activity")->assertForbidden();
    }
}
