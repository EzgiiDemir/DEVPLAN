<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Services\DevEngine\ChangeSetConflictDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeSetConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Conflict Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_an_unapplied_changeset_file_on_the_same_path_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $other = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'First feature', 'status' => 'awaiting_diff_approval']);
        $changeSet = $other->changeSet()->create(['status' => 'draft']);
        $changeSet->files()->create(['path' => 'app/Models/Widget.php', 'action' => 'modify', 'applied' => false]);

        $thisRequest = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Second feature', 'status' => 'planning']);

        $detector = new ChangeSetConflictDetector;
        $result = $detector->detect($project, $thisRequest->id, ['app/Models/Widget.php']);

        $this->assertArrayHasKey('app/Models/Widget.php', $result);
        $this->assertSame($other->id, $result['app/Models/Widget.php']['feature_request_id']);
    }

    public function test_an_already_applied_changeset_file_is_not_a_conflict(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $other = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'First feature', 'status' => 'applied']);
        $changeSet = $other->changeSet()->create(['status' => 'draft']);
        $changeSet->files()->create(['path' => 'app/Models/Widget.php', 'action' => 'modify', 'applied' => true]);

        $thisRequest = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Second feature', 'status' => 'planning']);

        $detector = new ChangeSetConflictDetector;
        $result = $detector->detect($project, $thisRequest->id, ['app/Models/Widget.php']);

        $this->assertSame([], $result);
    }

    public function test_the_requests_own_earlier_changeset_is_excluded(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $thisRequest = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Second feature', 'status' => 'planning']);
        $changeSet = $thisRequest->changeSet()->create(['status' => 'draft']);
        $changeSet->files()->create(['path' => 'app/Models/Widget.php', 'action' => 'modify', 'applied' => false]);

        $detector = new ChangeSetConflictDetector;
        $result = $detector->detect($project, $thisRequest->id, ['app/Models/Widget.php']);

        $this->assertSame([], $result);
    }

    public function test_a_different_path_is_not_flagged(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $other = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'First feature', 'status' => 'awaiting_diff_approval']);
        $changeSet = $other->changeSet()->create(['status' => 'draft']);
        $changeSet->files()->create(['path' => 'app/Models/Widget.php', 'action' => 'modify', 'applied' => false]);

        $thisRequest = $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Second feature', 'status' => 'planning']);

        $detector = new ChangeSetConflictDetector;
        $result = $detector->detect($project, $thisRequest->id, ['app/Models/Unrelated.php']);

        $this->assertSame([], $result);
    }

    public function test_a_conflict_in_a_different_project_is_ignored(): void
    {
        $user = User::factory()->create();
        $projectA = $this->projectFor($user);
        $projectB = $this->projectFor($user);

        $other = $projectB->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Other project feature', 'status' => 'awaiting_diff_approval']);
        $changeSet = $other->changeSet()->create(['status' => 'draft']);
        $changeSet->files()->create(['path' => 'app/Models/Widget.php', 'action' => 'modify', 'applied' => false]);

        $thisRequest = $projectA->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'This project feature', 'status' => 'planning']);

        $detector = new ChangeSetConflictDetector;
        $result = $detector->detect($projectA, $thisRequest->id, ['app/Models/Widget.php']);

        $this->assertSame([], $result);
    }
}
