<?php

namespace Tests\Feature;

use App\Models\Module;
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

    // ProjectController::store() already creates an empty 'tech_stack'
    // Module for every project; this fills in the ModuleItem a real Tech
    // Stack Advisor selection would produce.
    private function setTechStack(Project $project, string $frontend, string $backend, string $database): void
    {
        $module = Module::where('project_id', $project->id)->where('module_type', 'tech_stack')->firstOrFail();
        $module->items()->create([
            'item_type' => 'tech_stack',
            'content' => [
                'frontend' => ['selected' => $frontend],
                'backend' => ['selected' => $backend],
                'database' => ['selected' => $database],
            ],
        ]);
    }

    /**
     * store()/generate() now return 202 + a job id instead of the result
     * directly (Subsystem 3 — Queue System). Under the `sync` queue driver
     * used in tests, dispatch() runs the job inline before this returns, so
     * polling GET /ai-jobs/{id} immediately after already sees the final
     * 'succeeded' state — this helper does exactly what the real frontend
     * polling loop does, just without needing to actually wait.
     */
    private function storeFeatureAndGetResult(User $user, Project $project, string $prompt): array
    {
        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/features", ['prompt' => $prompt]);
        $store->assertStatus(202);

        $job = $this->actingAs($user)->getJson("/api/ai-jobs/{$store->json('job_id')}");
        $job->assertOk()->assertJsonPath('status', 'succeeded');

        return $job->json('result');
    }

    private function generateAndGetResult(User $user, Project $project, int $featureRequestId, array $body = []): array
    {
        $generate = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/generate",
            $body,
        );
        $generate->assertStatus(202);

        $job = $this->actingAs($user)->getJson("/api/ai-jobs/{$generate->json('job_id')}");
        $job->assertOk()->assertJsonPath('status', 'succeeded');

        return $job->json('result');
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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature so users can save products.');
        $featureRequestId = $result['feature_request_id'];

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $show->assertOk()
            ->assertJsonPath('status', 'awaiting_plan_approval')
            ->assertJsonCount(2, 'change_set.files');

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'app/Models/Wishlist.php',
            'action' => 'create',
            'plan_approved' => false,
        ]);
    }

    /**
     * Both planned files are 'modify' claims against paths that don't exist
     * in the project's index at all — a strong hallucination signal, and no
     * indexed files exist for the prompt to genuinely keyword-match either.
     * Covers Subsystem 4 (Confidence Model) end to end through the real
     * generatePlan() pipeline, not just ConfidenceScorer in isolation.
     */
    public function test_a_plan_with_no_real_file_matches_gets_a_low_confidence_level(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'app/Models/DoesNotExist.php', 'action' => 'modify', 'reason' => 'r1'],
                    ['path' => 'app/Services/AlsoMissing.php', 'action' => 'modify', 'reason' => 'r2'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $result = $this->storeFeatureAndGetResult($user, $project, 'Completely unrelated gibberish prompt zzz.');
        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$result['feature_request_id']}");

        $show->assertOk()->assertJsonPath('change_set.confidence_level', 'low');
    }

    /**
     * Covers Subsystem 5 (Duplicate Feature Detection) end to end: a second,
     * near-identical request against the same project surfaces a warning
     * pointing at the first one, and it also depresses the confidence score
     * (Subsystem 4's duplicateRisk factor) rather than the two systems being
     * unaware of each other.
     */
    public function test_a_near_duplicate_request_surfaces_a_warning_and_lowers_confidence(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn("<?php\n// wishlist model\n");
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Wishlist.php', 'action' => 'modify', 'reason' => 'model']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $first = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature so users can save products.');

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$first['feature_request_id']}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );
        $this->generateAndGetResult($user, $project, $first['feature_request_id']);
        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$first['feature_request_id']}/diff/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );
        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$first['feature_request_id']}/apply",
            [
                'applied_paths' => ['app/Models/Wishlist.php'],
                'before' => ['hash' => str_repeat('a', 40), 'message' => 'before'],
                'after' => ['hash' => str_repeat('b', 40), 'message' => 'after'],
            ],
        );

        $second = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature so users can save products for later.');
        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$second['feature_request_id']}");

        $show->assertOk()->assertJsonPath('change_set.duplicate_warning.type', 'similar_prior_request');
        $this->assertSame($first['feature_request_id'], $show->json('change_set.duplicate_warning.feature_request_id'));
    }

    /**
     * Covers Subsystem 6 (Architecture Validation) end to end: a project
     * recorded as PostgreSQL gets a migration generated with MySQL's
     * AUTO_INCREMENT syntax (simulating the AI ignoring the stack), and the
     * mismatch is flagged on the file plus reflected in the change set's
     * confidence score.
     */
    public function test_generated_content_that_violates_the_recorded_tech_stack_is_flagged(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'database/migrations/2026_01_01_create_widgets_table.php', 'action' => 'create', 'reason' => 'schema'],
                ],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn(
                "CREATE TABLE widgets (id INT AUTO_INCREMENT PRIMARY KEY);",
            );
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $this->setTechStack($project, 'React', 'Laravel', 'PostgreSQL');

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a widgets table.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['database/migrations/2026_01_01_create_widgets_table.php']],
        );

        $this->generateAndGetResult($user, $project, $featureRequestId);

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $files = collect($show->json('change_set.files'));
        $this->assertStringContainsString('AUTO_INCREMENT', $files->first()['architecture_warning']);
        $this->assertSame('low', $show->json('change_set.confidence_level'));
    }

    public function test_generated_content_matching_the_recorded_tech_stack_is_not_flagged(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => 'database/migrations/2026_01_01_create_widgets_table.php', 'action' => 'create', 'reason' => 'schema'],
                ],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn(
                "CREATE TABLE widgets (id SERIAL PRIMARY KEY);",
            );
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $this->setTechStack($project, 'React', 'Laravel', 'PostgreSQL');

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a widgets table.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['database/migrations/2026_01_01_create_widgets_table.php']],
        );

        $this->generateAndGetResult($user, $project, $featureRequestId);

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $files = collect($show->json('change_set.files'));
        $this->assertNull($files->first()['architecture_warning']);
    }

    public function test_show_labels_each_files_real_risk_level(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [
                    ['path' => '.env', 'action' => 'modify', 'reason' => 'add a var'],
                    ['path' => 'database/migrations/2026_01_01_add_thing.php', 'action' => 'create', 'reason' => 'schema change'],
                    ['path' => 'app/Models/Widget.php', 'action' => 'delete', 'reason' => 'no longer needed'],
                    ['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'new model'],
                ],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $result = $this->storeFeatureAndGetResult($user, $project, 'risky changes');
        $featureRequestId = $result['feature_request_id'];

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $show->assertOk();

        $byPath = collect($show->json('change_set.files'))->keyBy('path');
        $this->assertSame('high', $byPath['.env']['risk_level']);
        $this->assertSame('high', $byPath['database/migrations/2026_01_01_add_thing.php']['risk_level']);
        $this->assertSame('high', $byPath['app/Models/Widget.php']['risk_level']);
        $this->assertSame('low', $byPath['app/Models/Wishlist.php']['risk_level']);
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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $featureRequestId = $result['feature_request_id'];

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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php', 'app/Http/Controllers/WishlistController.php']],
        );

        $this->generateAndGetResult($user, $project, $featureRequestId);

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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );

        $this->generateAndGetResult($user, $project, $featureRequestId);

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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php']],
        );
        $this->generateAndGetResult($user, $project, $featureRequestId);
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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$result['feature_request_id']}");

        $paths = collect($show->json('change_set.files'))->pluck('path');
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

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a wishlist feature.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Wishlist.php', 'README.md']],
        );

        $this->generateAndGetResult($user, $project, $featureRequestId, [
            'files' => [['path' => 'README.md', 'content' => "# My Project\n"]],
        ]);

        $this->assertCount(1, $capturedReadmePrompts);
        $this->assertStringContainsString('My Project', $capturedReadmePrompts[0]);

        $this->assertDatabaseHas('change_set_files', [
            'path' => 'README.md',
            'new_content' => "# My Project\n\nNow with wishlists.\n",
        ]);
    }

    /**
     * Covers Subsystem 7 (Static Security Scan) end to end: generated
     * content containing a hardcoded secret is flagged with a structured
     * finding, and the pre-existing risk_level pill (Subsystem 7 also
     * extends RiskAnalyzer::classifyFile() to accept content) escalates to
     * 'high' even though the path itself looks unremarkable.
     */
    public function test_generated_content_with_a_hardcoded_secret_is_flagged_and_escalates_risk(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Services/PaymentService.php', 'action' => 'create', 'reason' => 'payments']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn(
                "<?php\nclass PaymentService {\n    private \$apiKey = 'sk_live_abcdefgh12345678';\n}\n",
            );
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a payment service.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Services/PaymentService.php']],
        );
        $this->generateAndGetResult($user, $project, $featureRequestId);

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $file = collect($show->json('change_set.files'))->first();

        $this->assertSame('high', $file['risk_level']);
        $this->assertNotEmpty($file['security_findings']);
        $this->assertSame('hardcoded_secret', $file['security_findings'][0]['category']);
    }

    public function test_clean_generated_content_has_no_security_findings(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Widget.php', 'action' => 'create', 'reason' => 'model']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn("<?php\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a widget model.');
        $featureRequestId = $result['feature_request_id'];

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$featureRequestId}/plan/approve",
            ['approved_paths' => ['app/Models/Widget.php']],
        );
        $this->generateAndGetResult($user, $project, $featureRequestId);

        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$featureRequestId}");
        $file = collect($show->json('change_set.files'))->first();

        $this->assertNull($file['security_findings']);
    }

    /**
     * Covers Subsystem 9 (ChangeSet Conflict Detection) end to end: a
     * second feature request proposing changes to a file another,
     * still-unapplied feature is also touching gets a conflict_warning
     * pointing at that other feature. Also confirms generateContent()
     * persists a real base_content_hash for a 'create' action (hash of "",
     * since no prior content is expected).
     */
    public function test_a_conflicting_in_flight_changeset_is_flagged(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Widget.php', 'action' => 'create', 'reason' => 'model']],
            ]));
            $mock->shouldReceive('generate')->once()->andReturn("<?php\nclass Widget {}\n");
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Widget.php', 'action' => 'modify', 'reason' => 'also touches it']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $first = $this->storeFeatureAndGetResult($user, $project, 'Add a widget model.');
        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$first['feature_request_id']}/plan/approve",
            ['approved_paths' => ['app/Models/Widget.php']],
        );
        $this->generateAndGetResult($user, $project, $first['feature_request_id']);
        // Left at 'awaiting_diff_approval' — never applied — so it's still
        // "in flight" when the second request plans against the same path.

        $second = $this->storeFeatureAndGetResult($user, $project, 'Also change the widget model.');
        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$second['feature_request_id']}");
        $file = collect($show->json('change_set.files'))->first();

        $this->assertSame($first['feature_request_id'], $file['conflict_warning']['feature_request_id']);

        $firstShow = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$first['feature_request_id']}");
        $firstFile = collect($firstShow->json('change_set.files'))->first();
        $this->assertSame(hash('sha256', ''), $firstFile['base_content_hash']);
    }

    public function test_no_conflict_is_flagged_when_no_other_feature_touches_the_same_path(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Widget.php', 'action' => 'create', 'reason' => 'model']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add a widget model.');
        $show = $this->actingAs($user)->getJson("/api/projects/{$project->id}/features/{$result['feature_request_id']}");
        $file = collect($show->json('change_set.files'))->first();

        $this->assertNull($file['conflict_warning']);
    }

    /**
     * Covers Subsystem 11 (Coding Standards) end to end: generateContent()'s
     * prompt to the AI actually includes the real naming already used in
     * this project's indexed files, not just the feature request itself.
     */
    public function test_generate_content_prompt_includes_the_projects_existing_naming_conventions(): void
    {
        $capturedUserPrompt = null;

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedUserPrompt) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Order.php', 'action' => 'create', 'reason' => 'model']],
            ]));
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedUserPrompt) {
                    $capturedUserPrompt = $user;

                    return "<?php\nclass Order {}\n";
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'app/Models/Wishlist.php',
            'language' => 'php',
            'content_hash' => 'x',
            'symbols' => ['Wishlist'],
        ]);

        $result = $this->storeFeatureAndGetResult($user, $project, 'Add an order model.');
        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/features/{$result['feature_request_id']}/plan/approve",
            ['approved_paths' => ['app/Models/Order.php']],
        );
        $this->generateAndGetResult($user, $project, $result['feature_request_id']);

        $this->assertStringContainsString('app/Models/Wishlist.php: Wishlist', $capturedUserPrompt);
    }
}
