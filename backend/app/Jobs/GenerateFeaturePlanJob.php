<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Project;
use App\Models\User;
use App\Services\DevEngine\FeatureAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper around FeatureAgentService::createAndPlan() — the one AI
 * call behind FeatureController::store(). The service itself is untouched;
 * this only moves the call off the request thread.
 */
class GenerateFeaturePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $aiJobId) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(FeatureAgentService $agent): void
    {
        $aiJob = AiJob::find($this->aiJobId);
        if (! $aiJob || $aiJob->cancelled_at) {
            return;
        }

        // Re-establishes the same request_id the HTTP request that created
        // this job had, if any — a queue worker is a separate PHP process,
        // so Log::withContext() from that request never reaches here on its
        // own; the id has to travel with the job's own payload instead.
        if ($requestId = $aiJob->payload['request_id'] ?? null) {
            Log::withContext(['request_id' => $requestId]);
        }

        $aiJob->update(['status' => 'running', 'attempts' => $aiJob->attempts + 1]);
        Log::info('ai_job.started', ['ai_job_id' => $aiJob->id, 'type' => $aiJob->type]);

        $project = Project::findOrFail($aiJob->payload['project_id']);
        $user = User::findOrFail($aiJob->payload['user_id']);

        $featureRequest = $agent->createAndPlan(
            $project,
            $user,
            $aiJob->payload['prompt'],
            $aiJob->payload['active_file'] ?? null,
        );

        $aiJob->update(['status' => 'succeeded', 'result' => ['feature_request_id' => $featureRequest->id]]);
        Log::info('ai_job.succeeded', ['ai_job_id' => $aiJob->id, 'type' => $aiJob->type]);
    }

    public function failed(?Throwable $exception): void
    {
        AiJob::where('id', $this->aiJobId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Unknown error.', 0, 1000),
        ]);
        Log::error('ai_job.failed', ['ai_job_id' => $this->aiJobId, 'error' => $exception?->getMessage()]);
    }
}
