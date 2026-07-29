<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Feature Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_store_generates_a_plan_from_the_ai_and_persists_change_set_files(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'summary' => 'Add a wishlist feature.',
                'files' => [
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'New model to store wishlist items.'],
                    ['path' => 'routes/api.php', 'action' => 'modify', 'reason' => 'Register the wishlist routes.'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature so users can save products.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'awaiting_plan_approval')
            ->assertJsonCount(2, 'change_set.files');

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'action' => 'create',
            'plan_approved' => false,
        ]);
    }

    public function test_approve_plan_marks_approved_files_and_drops_rejected_ones(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'r1'],
                    ['path' => 'app/Models/Unrelated.php', 'action' => 'create', 'reason' => 'r2'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);
        $featureRequestId = $store->json('id');

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );

        $response->assertOk()->assertJson(['needsContent' => []]);

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'plan_approved' => true,
        ]);
        $this->assertDatabaseMissing('change_set_files', ['path' => 'app/Models/Unrelated.php']);
    }

    public function test_generate_produces_file_content_in_dependency_order_without_touching_disk(): void
    {
        $callOrder = [];

        $this->mock(AiTextGenerator::class, function ($mock) use (&$callOrder) {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(json_encode([
                    'files' => [
                        ['path' => 'app/Http/Controllers/WishlistController.php', 'action' => 'create', 'reason' => 'controller'],
                        ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model'],
                    ],
                ]));

            $mock->shouldReceive('generate')
                ->twice()
                ->andReturnUsing(function (string $system, string $user) use (&$callOrder) {
                    $callOrder[] = str_contains($user, 'Wishlist.php') && ! str_contains($user, 'Controller') ? 'model' : 'controller';

                    return "<?php\n// generated content\n";
                });

            $mock->shouldReceive('generate')->zeroOrMoreTimes()->andReturn('{}');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);
        $featureRequestId = $store->json('id');

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php', 'app/Http/Controllers/WishlistController.php']],
        );

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/generate",
            [],
        );

        $response->assertOk();

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'new_content' => "<?php\n// generated content\n",
        ]);
        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Http/Controllers/WishlistController.php',
            'new_content' => "<?php\n// generated content\n",
        ]);

        // Model must be generated before the controller that depends on it.
        $this->assertSame(['model', 'controller'], $callOrder);
    }

    public function test_feature_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/features")
            ->assertForbidden();
    }

    public function test_full_apply_flow_records_checkpoints_and_marks_files_applied(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model'],
                ],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn("<?php\n// wishlist model\n");
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);
        $featureRequestId = $store->json('id');

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/generate",
            [],
        );

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/diff/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        )->assertOk();

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'diff_approved' => true,
            'applied' => false,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/apply",
            [
                'applied_paths' => ['app/Models/Wishlist.php'],
                'before' => ['hash' => str_repeat('a', 40), 'message' => 'DevPlan before: wishlist'],
                'after' => ['hash' => str_repeat('b', 40), 'message' => 'DevPlan feat: wishlist'],
            ],
        );

        $response->assertOk()->assertJsonPath('feature_request.status', 'applied');

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'applied' => true,
        ]);
        $this->assertDatabaseHas('checkpoints', [
            'project_id' => $project->id,
            'git_commit_hash' => str_repeat('a', 40),
            'message' => 'DevPlan before: wishlist',
        ]);
        $this->assertDatabaseHas('checkpoints', [
            'project_id' => $project->id,
            'git_commit_hash' => str_repeat('b', 40),
            'message' => 'DevPlan feat: wishlist',
        ]);

        $checkpoints = $this->actingAs($user)->getJson("/api/projects/{$project->id}/checkpoints");
        $checkpoints->assertOk()->assertJsonCount(2);
    }

    public function test_apply_rejects_a_malformed_commit_hash(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn('<?php');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);
        $featureRequestId = $store->json('id');

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );
        $this->actingAs($user)->postJson("/api/projects/{$project->id}/features/{$featureRequestId}/generate", []);
        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/diff/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/apply",
            [
                'applied_paths' => ['app/Models/Wishlist.php'],
                'before' => ['hash' => 'not-a-hash; rm -rf /', 'message' => 'x'],
                'after' => ['hash' => str_repeat('b', 40), 'message' => 'y'],
            ],
        );

        $response->assertStatus(422);
    }

    public function test_plan_automatically_adds_a_readme_entry_when_project_has_one_indexed(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create(['path' => 'README.md', 'language' => 'markdown', 'content_hash' => 'x']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);

        $paths = collect($response->json('change_set.files'))->pluck('path');
        $this->assertTrue($paths->contains('README.md'));
    }

    public function test_generate_uses_documentation_service_prompt_for_the_readme_file(): void
    {
        $capturedReadmePrompts = [];

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedReadmePrompts) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model'],
                    ['path' => 'README.md', 'action' => 'modify', 'reason' => 'docs'],
                ],
            ]));

            $mock->shouldReceive('generate')
                ->twice()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedReadmePrompts) {
                    if (str_contains($system, 'README')) {
                        $capturedReadmePrompts[] = $user;

                        return "# My Project\n\nNow with wishlists.\n";
                    }

                    return "<?php\n// wishlist model\n";
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create(['path' => 'README.md', 'language' => 'markdown', 'content_hash' => 'x']);

        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", [
            'prompt' => 'Add a wishlist feature.',
        ]);
        $featureRequestId = $store->json('id');

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php', 'README.md']],
        );

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/generate",
            ['files' => [['path' => 'README.md', 'content' => "# My Project\n"]]],
        );

        $response->assertOk();
        $this->assertCount(1, $capturedReadmePrompts);
        $this->assertStringContainsString('My Project', $capturedReadmePrompts[0]);

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'README.md',
            'new_content' => "# My Project\n\nNow with wishlists.\n",
        ]);
    }
}
