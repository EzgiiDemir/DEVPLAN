<?php

namespace Tests\Feature;

use App\Jobs\GenerateFeaturePlanJob;
use App\Models\AiJob;
use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use App\Services\DevEngine\FeatureAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the AiJob queue infrastructure itself (Subsystem 3), as opposed to
 * FeatureControllerTest/MayaControllerTest which cover the individual
 * endpoints that now dispatch onto it. Three things specifically need
 * direct-job-instantiation rather than going through the real HTTP
 * endpoints: failure handling and the cancellation guard both behave
 * differently under the `sync` queue driver used in tests than under a real
 * async queue worker (see the comment on each test), so they're exercised by
 * constructing and running the Job class the same way a real
 * `php artisan queue:work` worker would.
 */
class AiJobTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'AI Job Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_show_returns_the_jobs_current_state(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'succeeded',
            'payload' => ['prompt' => 'x'],
            'result' => ['feature_request_id' => 42],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/ai-jobs/{$aiJob->id}");

        $response->assertOk()->assertJson([
            'id' => $aiJob->id,
            'type' => 'feature_plan',
            'status' => 'succeeded',
            'result' => ['feature_request_id' => 42],
            'error' => null,
        ]);
    }

    public function test_show_is_forbidden_for_a_user_with_no_access_to_the_jobs_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => ['prompt' => 'x'],
        ]);

        $this->actingAs($intruder)->getJson("/api/v1/ai-jobs/{$aiJob->id}")->assertForbidden();
    }

    public function test_cancel_marks_a_still_queued_job_cancelled(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => ['prompt' => 'x'],
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/ai-jobs/{$aiJob->id}/cancel");

        $response->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertNotNull($aiJob->fresh()->cancelled_at);
    }

    /**
     * Cancellation can only prevent work that hasn't started — it must not
     * silently relabel a job that has already produced a real result. A
     * worker can't be interrupted mid-AI-call, so once a job is running (or
     * further along), cancel is a no-op.
     */
    public function test_cancel_does_not_affect_a_job_that_already_succeeded(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'succeeded',
            'payload' => ['prompt' => 'x'],
            'result' => ['feature_request_id' => 1],
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/ai-jobs/{$aiJob->id}/cancel");

        $response->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertNull($aiJob->fresh()->cancelled_at);
    }

    /**
     * Models exactly what a real `php artisan queue:work` worker does when
     * it picks up a job that was cancelled while still queued: it still
     * calls handle() (the queue driver has no idea about our own
     * `cancelled_at` column), and the job's own guard must be what stops it
     * from doing the AI call or overwriting the cancelled status.
     */
    public function test_a_cancelled_job_never_calls_the_ai_and_leaves_its_status_alone(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->never();
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'payload' => ['project_id' => $project->id, 'user_id' => $user->id, 'prompt' => 'Add a wishlist.'],
        ]);

        (new GenerateFeaturePlanJob($aiJob->id))->handle(app(FeatureAgentService::class));

        $aiJob->refresh();
        $this->assertSame('cancelled', $aiJob->status);
        $this->assertSame(0, $aiJob->attempts);
        $this->assertDatabaseMissing('feature_requests', ['project_id' => $project->id]);
    }

    /**
     * Under the real `database` queue driver, a job that throws is retried
     * per $tries/backoff by the worker before failed() is ever called; under
     * the `sync` driver used in tests, dispatch() would both invoke
     * failed() AND rethrow synchronously into the HTTP response, which isn't
     * representative of the real async path. Running handle()/failed()
     * directly, the same way one real worker attempt does, tests our own
     * status-transition logic without that test-environment artifact.
     */
    public function test_a_failing_job_is_marked_failed_with_the_error_recorded(): void
    {
        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new RuntimeException('AI provider unavailable.'));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => ['project_id' => $project->id, 'user_id' => $user->id, 'prompt' => 'Add a wishlist.'],
        ]);

        $job = new GenerateFeaturePlanJob($aiJob->id);

        try {
            $job->handle(app(FeatureAgentService::class));
            $this->fail('Expected the AI failure to propagate.');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $aiJob->refresh();
        $this->assertSame('failed', $aiJob->status);
        $this->assertSame('AI provider unavailable.', $aiJob->error);
        $this->assertSame(1, $aiJob->attempts);
    }

    public function test_a_missing_ai_job_row_is_a_silent_no_op(): void
    {
        // A job dispatched for an AiJob row that's since been deleted
        // (e.g. the project itself was deleted) must not throw.
        (new GenerateFeaturePlanJob(999999))->handle(app(FeatureAgentService::class));
        $this->assertTrue(true);
    }

    /**
     * Covers Subsystem 12 (Production Logging): a successful job run writes
     * structured start/succeeded log lines — "queue logs" — not just the
     * AiJob row's own status column.
     */
    public function test_a_successful_job_run_logs_started_and_succeeded(): void
    {
        Log::spy();

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'files' => [['path' => 'app/Models/Wishlist.php', 'action' => 'create', 'reason' => 'model']],
            ]));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => ['project_id' => $project->id, 'user_id' => $user->id, 'prompt' => 'Add a wishlist.', 'request_id' => 'trace-abc'],
        ]);

        (new GenerateFeaturePlanJob($aiJob->id))->handle(app(FeatureAgentService::class));

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'ai_job.started' && $context['ai_job_id'] === $aiJob->id)
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'ai_job.succeeded' && $context['ai_job_id'] === $aiJob->id)
            ->once();
    }

    public function test_a_failing_job_logs_the_failure(): void
    {
        Log::spy();

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new RuntimeException('AI provider unavailable.'));
        });

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => ['project_id' => $project->id, 'user_id' => $user->id, 'prompt' => 'Add a wishlist.'],
        ]);

        $job = new GenerateFeaturePlanJob($aiJob->id);
        try {
            $job->handle(app(FeatureAgentService::class));
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'ai_job.failed' && $context['ai_job_id'] === $aiJob->id)
            ->once();
    }
}
