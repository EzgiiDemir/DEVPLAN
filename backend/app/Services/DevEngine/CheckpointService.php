<?php

namespace App\Services\DevEngine;

use App\Models\Checkpoint;
use App\Models\FeatureRequest;
use App\Models\Project;

/**
 * Records checkpoint metadata after the frontend has already run the actual
 * `git commit` through Companion — this service never touches git or the
 * user's disk itself, it only durably remembers the commit hash so the UI
 * can offer a one-click rollback later.
 */
class CheckpointService
{
    public function record(Project $project, ?FeatureRequest $featureRequest, string $gitCommitHash, string $message): Checkpoint
    {
        return $project->checkpoints()->create([
            'feature_request_id' => $featureRequest?->id,
            'git_commit_hash' => $gitCommitHash,
            'message' => $message,
        ]);
    }
}
