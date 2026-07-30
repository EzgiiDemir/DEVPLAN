<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\ChangeSet;
use App\Services\DevEngine\FeatureAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper around FeatureAgentService::generateContent() — the one AI
 * call behind FeatureController::generate(), and the longest-running of the
 * three jobs here since it makes one AI call per approved file, sequentially.
 */
class GenerateFeatureContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

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

        if ($requestId = $aiJob->payload['request_id'] ?? null) {
            Log::withContext(['request_id' => $requestId]);
        }

        $aiJob->update(['status' => 'running', 'attempts' => $aiJob->attempts + 1]);
        Log::info('ai_job.started', ['ai_job_id' => $aiJob->id, 'type' => $aiJob->type]);

        $changeSet = ChangeSet::findOrFail($aiJob->payload['change_set_id']);
        $updated = $agent->generateContent($changeSet, $aiJob->payload['current_content_by_path'] ?? []);

        $aiJob->update(['status' => 'succeeded', 'result' => ['change_set_id' => $updated->id]]);
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
