<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user, string $title = 'Search Test Project'): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => $title]);

        return Project::findOrFail($response->json('id'));
    }

    public function test_search_finds_a_matching_project_by_title(): void
    {
        $user = User::factory()->create();
        $this->projectFor($user, 'Wishlist Feature Rollout');

        $response = $this->actingAs($user)->getJson('/api/v1/search?q=wishlist');

        $response->assertOk();
        $this->assertCount(1, $response->json('projects'));
        $this->assertSame('Wishlist Feature Rollout', $response->json('projects.0.title'));
    }

    public function test_search_finds_a_matching_task_title(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->tasks()->create(['title' => 'Fix the Stripe webhook retry logic', 'status' => 'todo']);
        $project->tasks()->create(['title' => 'Unrelated task', 'status' => 'todo']);

        $response = $this->actingAs($user)->getJson('/api/v1/search?q=stripe');

        $response->assertOk()->assertJsonCount(1, 'tasks');
        $this->assertSame('Fix the Stripe webhook retry logic', $response->json('tasks.0.title'));
        $this->assertSame($project->title, $response->json('tasks.0.project_title'));
    }

    public function test_search_finds_a_matching_comment_body(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->comments()->create([
            'user_id' => $user->id,
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'body' => 'Can we revisit the onboarding checklist copy?',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/search?q=onboarding');

        $response->assertOk()->assertJsonCount(1, 'comments');
        $this->assertStringContainsString('onboarding', $response->json('comments.0.excerpt'));
    }

    public function test_search_finds_a_matching_feature_request_prompt(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->featureRequests()->create(['user_id' => $user->id, 'prompt' => 'Add a dark mode toggle', 'status' => 'draft']);

        $response = $this->actingAs($user)->getJson('/api/v1/search?q=dark mode');

        $response->assertOk()->assertJsonCount(1, 'features');
    }

    public function test_search_finds_text_inside_module_item_content(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $module = Module::where('project_id', $project->id)->where('module_type', 'tech_stack')->firstOrFail();
        $module->items()->create([
            'item_type' => 'tech_stack',
            'content' => ['backend' => ['selected' => 'Laravel'], 'notes' => 'Chosen for its queue system.'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/search?q=queue system');

        $response->assertOk()->assertJsonCount(1, 'module_items');
        $this->assertSame('tech_stack', $response->json('module_items.0.module_type'));
    }

    public function test_search_does_not_leak_results_from_projects_the_user_cannot_access(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $this->projectFor($owner, 'Confidential Wishlist Project');

        $response = $this->actingAs($outsider)->getJson('/api/v1/search?q=wishlist');

        $response->assertOk()->assertJsonCount(0, 'projects');
    }

    public function test_a_query_shorter_than_two_characters_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/search?q=a')->assertStatus(422);
    }
}
