<?php

namespace App\Services\DevEngine;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

/**
 * Records what the frontend already did through Companion (ran the real
 * platform CLI, captured its real output and live URL) — this service never
 * touches git, a deploy CLI, or the user's disk itself, the same posture
 * CheckpointService already has for applied features. A successful deploy
 * reuses CheckpointService::record() unchanged, so "connect deployment
 * history with Git checkpoints" is a foreign key, not a second system.
 */
class DeploymentService
{
    public function __construct(private CheckpointService $checkpoints) {}

    public function start(Project $project, User $user, string $platform): Deployment
    {
        return $project->deployments()->create([
            'user_id' => $user->id,
            'platform' => $platform,
            'status' => 'preparing',
        ]);
    }

    public function recordProgress(Deployment $deployment, string $status, ?string $logOutput = null): Deployment
    {
        $data = ['status' => $status];
        if ($logOutput !== null) {
            $data['log_output'] = trim(($deployment->log_output ?? '')."\n".$logOutput);
        }

        $deployment->update($data);

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
            'log_output' => trim(($deployment->log_output ?? '')."\n".$finalLogOutput),
            'error_message' => $errorMessage,
            'checkpoint_id' => $checkpoint?->id,
        ]);

        return $deployment->fresh();
    }
}
