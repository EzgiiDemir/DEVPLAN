<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MayaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Maya Test']);

        return Project::findOrFail($response->json('id'));
    }

    /**
     * Maya's endpoint now returns 202 + a job id instead of the created
     * messages directly (Subsystem 3 — Queue System). Under the `sync`
     * queue driver used in tests, dispatch() runs the job inline before
     * this returns, so polling GET /ai-jobs/{id} immediately after already
     * sees the final 'succeeded' state — this helper mirrors exactly what
     * the real frontend polling loop does, just without needing to wait.
     */
    private function sendMayaMessageAndGetResult(User $user, Project $project, array $body): array
    {
        $store = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", $body);
        $store->assertStatus(202);

        $job = $this->actingAs($user)->getJson("/api/ai-jobs/{$store->json('job_id')}");
        $job->assertOk()->assertJsonPath('status', 'succeeded');

        return $job->json('result');
    }

    public function test_chat_intent_produces_a_conversational_reply_with_no_change_set(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'chat', 'subject' => 'greeting']));
            $mock->shouldReceive('generate')->once()->andReturn('Hi! I am Maya, ask me anything about this project.');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->sendMayaMessageAndGetResult($user, $project, ['message' => 'Hey, what can you help me with?']);

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/maya/messages");
        $history->assertOk();
        $messages = $history->json();
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('chat', $messages[0]['intent']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Hi! I am Maya, ask me anything about this project.', $messages[1]['content']);
        $this->assertNull($messages[1]['feature_request']);

        $this->assertDatabaseCount('maya_messages', 2);
        $this->assertDatabaseHas('maya_messages', ['project_id' => $project->id, 'role' => 'user', 'intent' => 'chat']);
    }

    public function test_active_file_hint_is_included_in_context_even_when_not_keyword_matched(): void
    {
        $capturedUserPrompt = null;

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedUserPrompt) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'chat']));
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedUserPrompt) {
                    $capturedUserPrompt = $user;

                    return 'ok';
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'src/components/LoginForm.jsx',
            'language' => 'javascript',
            'content_hash' => 'a',
            'summary' => 'Renders the login form.',
        ]);
        $project->files()->create([
            'path' => 'src/components/UnrelatedNameEntirely.jsx',
            'language' => 'javascript',
            'content_hash' => 'b',
            'summary' => 'Shares no keywords with the message below.',
        ]);

        // The message keyword-matches LoginForm only; without the active_file
        // hint, UnrelatedNameEntirely would never appear in context.
        $this->sendMayaMessageAndGetResult($user, $project, [
            'message' => 'Explain the login flow',
            'active_file' => 'src/components/UnrelatedNameEntirely.jsx',
        ]);

        $this->assertStringContainsString('LoginForm.jsx', $capturedUserPrompt);
        $this->assertStringContainsString('UnrelatedNameEntirely.jsx', $capturedUserPrompt);
    }

    public function test_explain_intent_grounds_the_reply_in_indexed_files_and_creates_no_change_set(): void
    {
        $capturedUserPrompt = null;

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedUserPrompt) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'explain', 'subject' => 'auth']));
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedUserPrompt) {
                    $capturedUserPrompt = $user;

                    return "How it works: ...\nFiles involved: ...\nData flow: ...\nSecurity concerns: ...";
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'app/Http/Controllers/AuthController.php',
            'language' => 'php',
            'content_hash' => 'x',
            'summary' => 'Handles login and registration.',
        ]);

        $this->sendMayaMessageAndGetResult($user, $project, ['message' => 'Explain the authentication system']);

        $this->assertStringContainsString('AuthController.php', $capturedUserPrompt);
        $this->assertDatabaseHas('maya_messages', ['project_id' => $project->id, 'role' => 'assistant', 'intent' => 'explain']);
    }

    public function test_debug_intent_includes_recent_activity_and_schema_context(): void
    {
        $capturedUserPrompt = null;

        $this->mock(AiTextGenerator::class, function ($mock) use (&$capturedUserPrompt) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'debug', 'subject' => 'login']));
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $system, string $user) use (&$capturedUserPrompt) {
                    $capturedUserPrompt = $user;

                    return "Problem: ...\nCause: ...\nAffected files: ...\nSolution: ...";
                });
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $project->files()->create([
            'path' => 'database/migrations/2026_01_01_create_users_table.php',
            'language' => 'php',
            'content_hash' => 'x',
            'summary' => 'Creates the users table.',
        ]);

        $this->sendMayaMessageAndGetResult($user, $project, [
            'message' => 'Login is broken: TypeError cannot read id of undefined',
        ]);

        $this->assertStringContainsString('Recent activity', $capturedUserPrompt);
        $this->assertStringContainsString('Database schema', $capturedUserPrompt);
        $this->assertStringContainsString('users table', $capturedUserPrompt);
    }

    public function test_feature_request_intent_delegates_to_the_existing_plan_pipeline(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'feature_request', 'subject' => 'wishlist']));
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'summary' => 'Add a wishlist feature.',
                'files' => [['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->sendMayaMessageAndGetResult($user, $project, [
            'message' => 'Add a wishlist feature so users can save products.',
        ]);

        $this->assertDatabaseHas('feature_requests', ['project_id' => $project->id, 'prompt' => 'Add a wishlist feature so users can save products.']);
        $this->assertDatabaseHas('change_set_files', ['path' => 'app/Models/Wishlist.php']);

        // The assistant message itself must carry the hydrated plan inline —
        // this is what lets a FeatureCard render without a second round-trip
        // after a page reload via index().
        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/maya/messages");
        $historyAssistant = collect($history->json())->firstWhere('role', 'assistant');
        $this->assertSame('feature_request', $historyAssistant['intent']);
        $this->assertNotNull($historyAssistant['feature_request_id']);
        $this->assertSame(
            'app/Models/Wishlist.php',
            $historyAssistant['feature_request']['change_set']['files'][0]['path'],
        );
    }

    public function test_refactor_intent_also_delegates_to_the_plan_pipeline_with_its_own_intent_label(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'refactor', 'subject' => 'widget']));
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'src/components/Widget.jsx', 'action' => 'modify', 'reason' => 'simplify']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->sendMayaMessageAndGetResult($user, $project, ['message' => 'Can you clean up the Widget component?']);

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/maya/messages");
        $assistantMessage = collect($history->json())->firstWhere('role', 'assistant');
        $this->assertSame('refactor', $assistantMessage['intent']);
        $this->assertNotNull($assistantMessage['feature_request_id']);
    }

    public function test_index_returns_history_in_chronological_order(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')
                ->times(4)
                ->andReturnUsing(fn (string $system) => str_contains($system, 'Classify')
                    ? json_encode(['intent' => 'chat'])
                    : 'ok');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->sendMayaMessageAndGetResult($user, $project, ['message' => 'first']);
        $this->sendMayaMessageAndGetResult($user, $project, ['message' => 'second']);

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/maya/messages");
        $history->assertOk();
        $contents = collect($history->json())->pluck('content');
        $this->assertSame(['first', 'ok', 'second', 'ok'], $contents->all());
    }

    public function test_maya_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/maya/messages")->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/projects/{$project->id}/maya/messages", ['message' => 'x'])->assertForbidden();
    }
}
