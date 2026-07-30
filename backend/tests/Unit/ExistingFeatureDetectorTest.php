<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Services\DevEngine\ExistingFeatureDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExistingFeatureDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Detector Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_a_near_identical_prior_request_is_flagged(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->featureRequests()->create([
            'user_id' => $user->id,
            'prompt' => 'Add a wishlist feature so users can save products.',
            'status' => 'applied',
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Add a wishlist feature so users can save products for later.');

        $this->assertNotNull($result);
        $this->assertSame('similar_prior_request', $result['type']);
    }

    public function test_an_unrelated_new_request_is_not_flagged_against_a_dissimilar_prior_request(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->featureRequests()->create([
            'user_id' => $user->id,
            'prompt' => 'Add a wishlist feature so users can save products.',
            'status' => 'applied',
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Set up email notifications for failed payments.');

        $this->assertNull($result);
    }

    /**
     * A prior request that's still just 'planning' (this exact request,
     * mid-generatePlan()) or was rejected must not count as a duplicate of
     * itself — only genuinely prior, still-relevant requests do.
     */
    public function test_a_request_still_in_planning_status_is_ignored(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->featureRequests()->create([
            'user_id' => $user->id,
            'prompt' => 'Add a wishlist feature so users can save products.',
            'status' => 'planning',
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Add a wishlist feature so users can save products.');

        $this->assertNull($result);
    }

    public function test_an_existing_model_symbol_matching_the_prompt_is_flagged(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'app/Models/Wishlist.php',
            'language' => 'php',
            'content_hash' => 'x',
            'symbols' => ['Wishlist'],
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Let customers save items to a wishlist for later.');

        $this->assertNotNull($result);
        $this->assertSame('existing_symbol', $result['type']);
        $this->assertSame('app/Models/Wishlist.php', $result['path']);
    }

    public function test_a_plural_prompt_word_still_matches_a_singular_symbol_name(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'app/Models/Wishlist.php',
            'language' => 'php',
            'content_hash' => 'x',
            'symbols' => ['Wishlist'],
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Users should be able to manage their wishlists.');

        $this->assertNotNull($result);
        $this->assertSame('existing_symbol', $result['type']);
    }

    public function test_a_genuinely_new_feature_with_no_matching_symbols_is_not_flagged(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'app/Models/Order.php',
            'language' => 'php',
            'content_hash' => 'x',
            'symbols' => ['Order'],
        ]);

        $detector = new ExistingFeatureDetector;
        $result = $detector->detect($project, 'Add a dark mode toggle to the settings page.');

        $this->assertNull($result);
    }
}
