<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Project;
use App\Models\User;
use App\Services\DevEngine\MayaChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper around MayaChatService::handleMessage() — the classify
 * call plus either a conversational reply or a delegation into
 * FeatureAgentService::createAndPlan(), all behind MayaController::store().
 * handleMessage() itself creates the user's MayaMessage row as its first
 * step; the frontend renders that message optimistically on send rather
 * than waiting for this job, so a queued turn doesn't feel like a delay
 * before the user's own message appears.
 */
class ProcessMayaMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $aiJobId) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(MayaChatService $maya): void
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

        $project = Project::findOrFail($aiJob->payload['project_id']);
        $user = User::findOrFail($aiJob->payload['user_id']);

        $result = $maya->handleMessage(
            $project,
            $user,
            $aiJob->payload['message'],
            $aiJob->payload['active_file'] ?? null,
        );

        $messageIds = collect($result['messages'])->pluck('id')->all();
        $aiJob->update(['status' => 'succeeded', 'result' => ['message_ids' => $messageIds]]);
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
