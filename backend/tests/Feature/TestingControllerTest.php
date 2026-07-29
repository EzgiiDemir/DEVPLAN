<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Testing Agent Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_detect_identifies_jest_from_package_json(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/detect", [
            'package_json_content' => json_encode(['devDependencies' => ['jest' => '^29.0.0']]),
        ]);

        $response->assertOk()->assertJsonPath('framework', 'jest');
    }

    public function test_record_parses_a_real_jest_json_payload_and_persists_a_test_run(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $resultJson = json_encode([
            'numTotalTests' => 2,
            'numPassedTests' => 1,
            'numFailedTests' => 1,
            'testResults' => [[
                'name' => 'src/math.test.js',
                'perfStats' => ['runtime' => 50],
                'assertionResults' => [
                    ['title' => 'adds', 'fullName' => 'Math adds', 'status' => 'passed', 'failureMessages' => []],
                    ['title' => 'subtracts', 'fullName' => 'Math subtracts', 'status' => 'failed', 'failureMessages' => ['Expected 4 got 3']],
                ],
            ]],
        ]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/record", [
            'framework' => 'jest',
            'result_file_content' => $resultJson,
            'exit_code' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('passed_count', 1)
            ->assertJsonPath('failed_count', 1);

        $this->assertDatabaseHas('test_runs', [
            'project_id' => $project->id,
            'framework' => 'jest',
            'status' => 'failed',
        ]);

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/tests");
        $history->assertOk()->assertJsonCount(1);
    }

    public function test_generate_delegates_to_the_existing_plan_pipeline_with_test_intent(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'src/math.test.js', 'action' => 'create', 'reason' => 'cover math.js']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create(['path' => 'src/math.js', 'language' => 'javascript', 'content_hash' => 'x']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/generate", [
            'path' => 'src/math.js',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('change_set'));

        $assistantMessage = collect($response->json('messages'))->firstWhere('role', 'assistant');
        $this->assertSame('test', $assistantMessage['intent']);
        $this->assertNotNull($assistantMessage['feature_request_id']);

        // The user-facing message row exists too, tagged with the same intent.
        $this->assertDatabaseHas('maya_messages', [
            'project_id' => $project->id,
            'role' => 'user',
            'intent' => 'test',
        ]);
    }

    public function test_suggest_fix_uses_the_stored_failure_to_build_a_real_fix_plan(): void
    {
        $capturedUserPrompt = null;

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedUserPrompt) {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedUserPrompt) {
                    $capturedUserPrompt = $user;

                    return json_encode(['files' => [['path' => 'src/math.js', 'action' => 'modify', 'reason' => 'fix the bug']]]);
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $recordResponse = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/record", [
            'framework' => 'jest',
            'exit_code' => 1,
            'result_file_content' => json_encode([
                'numTotalTests' => 1, 'numPassedTests' => 0, 'numFailedTests' => 1,
                'testResults' => [[
                    'name' => 'src/math.js',
                    'assertionResults' => [
                        ['title' => 'adds', 'fullName' => 'Math adds', 'status' => 'failed', 'failureMessages' => ['Expected 4, received 3']],
                    ],
                ]],
            ]),
        ]);
        $testRunId = $recordResponse->json('id');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/{$testRunId}/suggest-fix", [
            'failure_index' => 0,
        ]);

        $response->assertCreated();
        $assistantMessage = collect($response->json('messages'))->firstWhere('role', 'assistant');
        $this->assertSame('fix', $assistantMessage['intent']);
        $this->assertNotNull($response->json('change_set'));

        $this->assertStringContainsString('Math adds', $capturedUserPrompt);
        $this->assertStringContainsString('Expected 4, received 3', $capturedUserPrompt);
    }

    public function test_suggest_fix_rejects_an_out_of_range_failure_index(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $recordResponse = $this->actingAs($user)->postJson("/api/projects/{$project->id}/tests/record", [
            'framework' => 'jest',
            'exit_code' => 0,
            'result_file_content' => json_encode(['numTotalTests' => 1, 'numPassedTests' => 1, 'numFailedTests' => 0, 'testResults' => []]),
        ]);
        $testRunId = $recordResponse->json('id');

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/tests/{$testRunId}/suggest-fix", ['failure_index' => 0])
            ->assertNotFound();
    }

    public function test_testing_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/tests")->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/projects/{$project->id}/tests/detect", [])->assertForbidden();
    }
}
