<?php

namespace App\Services\DevEngine;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what the frontend already did through Companion (ran the real
 * platform CLI, captured its real output and live URL) — this service never
 * touches git, a deploy CLI, or the user's disk itself, the same posture
 * CheckpointService already has for applied features. A successful deploy
 * reuses CheckpointService::record() unchanged, so "connect deployment
 * history with Git checkpoints" is a foreign key, not a second system.
 *
 * checkHealth() is the one exception — a real outbound HTTP request this
 * service makes itself, to the deployment's own live_url, since "did the
 * CLI exit 0" and "is the app actually serving traffic" are different
 * questions and only the second one is a genuine post-deploy guarantee.
 */
class DeploymentService
{
    public function __construct(private CheckpointService $checkpoints) {}

    public function start(Project $project, User $user, string $platform): Deployment
    {
        $deployment = $project->deployments()->create([
            'user_id' => $user->id,
            'platform' => $platform,
            'status' => 'preparing',
        ]);

        Log::info('deployment.status_changed', [
            'deployment_id' => $deployment->id,
            'project_id' => $project->id,
            'platform' => $platform,
            'status' => 'preparing',
        ]);

        return $deployment;
    }

    /**
     * $companionProcessId is optional — set once, when the frontend starts
     * the actual background deploy process, so a dropped browser tab can
     * reattach to the same still-running process via Companion instead of
     * losing track of an in-progress deployment entirely.
     */
    public function recordProgress(Deployment $deployment, string $status, ?string $logOutput = null, ?string $companionProcessId = null): Deployment
    {
        $data = ['status' => $status];
        if ($logOutput !== null) {
            $data['log_output'] = trim(($deployment->log_output ?? '')."\n".$this->redactSecrets($logOutput));
        }
        if ($companionProcessId !== null) {
            $data['companion_process_id'] = $companionProcessId;
        }

        $deployment->update($data);

        Log::info('deployment.status_changed', [
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'status' => $status,
        ]);

        return $deployment->fresh();
    }

    public function finish(
        Deployment $deployment,
        bool $success,
        ?string $gitCommitHash,
        ?string $liveUrl,
        string $finalLogOutput,
        ?string $errorMessage = null,
    ): Deployment {
        $checkpoint = null;
        if ($success && $gitCommitHash) {
            $checkpoint = $this->checkpoints->record(
                $deployment->project,
                null,
                $gitCommitHash,
                "Deployed to {$deployment->platform}",
            );
        }

        $deployment->update([
            'status' => $success ? 'success' : 'failed',
            'git_commit_hash' => $gitCommitHash,
            'live_url' => $liveUrl,
            'log_output' => trim(($deployment->log_output ?? '')."\n".$this->redactSecrets($finalLogOutput)),
            'error_message' => $errorMessage,
            'checkpoint_id' => $checkpoint?->id,
            // The deploy process itself has exited either way — nothing left
            // to reattach to.
            'companion_process_id' => null,
        ]);

        Log::info('deployment.status_changed', [
            'deployment_id' => $deployment->id,
            'project_id' => $deployment->project_id,
            'status' => $success ? 'success' : 'failed',
            'live_url' => $liveUrl,
        ]);

        return $deployment->fresh();
    }

    /**
     * A real network request to the deployment's own reported live_url —
     * 2xx/3xx counts as healthy, anything else (including a connection
     * failure/timeout) as unhealthy. Never throws: an unreachable URL is a
     * legitimate, expected result to record, not an application error.
     */
    public function checkHealth(Deployment $deployment): Deployment
    {
        if (! $deployment->live_url) {
            $deployment->update(['health_status' => 'unknown', 'last_health_checked_at' => now()]);

            return $deployment->fresh();
        }

        try {
            $response = Http::timeout(5)->get($deployment->live_url);
            $healthy = $response->successful() || $response->redirect();
        } catch (Throwable) {
            $healthy = false;
        }

        $deployment->update([
            'health_status' => $healthy ? 'healthy' : 'unhealthy',
            'last_health_checked_at' => now(),
        ]);

        Log::info('deployment.health_checked', [
            'deployment_id' => $deployment->id,
            'health_status' => $healthy ? 'healthy' : 'unhealthy',
        ]);

        return $deployment->fresh();
    }

    /**
     * Deploy CLI output routinely echoes tokens (a platform auth token
     * printed during login, an API key a build script logs) — this runs on
     * every line persisted to `log_output`, which multiple team members can
     * read via the deployment history endpoint.
     */
    private function redactSecrets(string $text): string
    {
        $text = preg_replace(
            '/\b(api[_-]?key|secret|password|passwd|token|authorization)\b(\s*[:=]\s*)(["\']?)([A-Za-z0-9\-_\/+=.]{8,})\3/i',
            '$1$2$3[REDACTED]$3',
            $text,
        ) ?? $text;

        return preg_replace('/\bBearer\s+[A-Za-z0-9\-_.]{8,}/i', 'Bearer [REDACTED]', $text) ?? $text;
    }
}
