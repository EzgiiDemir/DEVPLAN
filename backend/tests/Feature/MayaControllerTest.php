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

    public function test_chat_intent_produces_a_conversational_reply_with_no_change_set(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['intent' => 'chat', 'subject' => 'greeting']));
            $mock->shouldReceive('generate')->once()->andReturn('Hi! I am Maya, ask me anything about this project.');
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Hey, what can you help me with?',
        ]);

        $response->assertCreated()->assertJsonPath('change_set', null);
        $messages = $response->json('messages');
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('chat', $messages[0]['intent']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Hi! I am Maya, ask me anything about this project.', $messages[1]['content']);

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
        $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Explain the login flow',
            'active_file' => 'src/components/UnrelatedNameEntirely.jsx',
        ])->assertCreated();

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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Explain the authentication system',
        ]);

        $response->assertCreated()->assertJsonPath('change_set', null);
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Login is broken: TypeError cannot read id of undefined',
        ]);

        $response->assertCreated()->assertJsonPath('change_set', null);
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Add a wishlist feature so users can save products.',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('change_set'));
        $this->assertCount(1, $response->json('change_set.files'));

        $assistantMessage = collect($response->json('messages'))->firstWhere('role', 'assistant');
        $this->assertSame('feature_request', $assistantMessage['intent']);
        $this->assertNotNull($assistantMessage['feature_request_id']);

        $this->assertDatabaseHas('feature_requests', ['project_id' => $project->id, 'prompt' => 'Add a wishlist feature so users can save products.']);
        $this->assertDatabaseHas('change_set_files', ['path' => 'app/Models/Wishlist.php']);

        // The assistant message itself must carry the hydrated plan inline —
        // this is what lets a FeatureCard render without a second round-trip,
        // both right after sending and after a page reload via index().
        $this->assertSame(
            'app/Models/Wishlist.php',
            $assistantMessage['feature_request']['change_set']['files'][0]['path'],
        );

        $history = $this->actingAs($user)->getJson("/api/projects/{$project->id}/maya/messages");
        $historyAssistant = collect($history->json())->firstWhere('role', 'assistant');
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

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", [
            'message' => 'Can you clean up the Widget component?',
        ]);

        $response->assertCreated();
        $assistantMessage = collect($response->json('messages'))->firstWhere('role', 'assistant');
        $this->assertSame('refactor', $assistantMessage['intent']);
        $this->assertNotNull($response->json('change_set'));
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

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", ['message' => 'first']);
        $this->actingAs($user)->postJson("/api/projects/{$project->id}/maya/messages", ['message' => 'second']);

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
