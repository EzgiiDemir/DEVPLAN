<?php

namespace App\Services\DevEngine;

use App\Models\ChangeSetFile;
use App\Models\Project;

/**
 * Detects when a newly planned file path is also touched by another,
 * still-unapplied ChangeSet in the same project — two feature requests
 * independently proposing changes to the same file. Informational only at
 * plan time (surfaced as a warning, generation still proceeds); the actual
 * *block* this subsystem's spec calls for happens where it has to — at the
 * point of writing to disk, which only the frontend/Companion can see (see
 * useFeatureFlow.js's pre-write content-hash check), not here.
 */
class ChangeSetConflictDetector
{
    /**
     * @param  array<int, string>  $paths
     * @return array<string, array{feature_request_id: int, prompt: string}> keyed by path
     */
    public function detect(Project $project, int $excludeFeatureRequestId, array $paths): array
    {
        if (! $paths) {
            return [];
        }

        $conflicting = ChangeSetFile::whereIn('path', $paths)
            ->where('applied', false)
            ->whereHas('changeSet.featureRequest', function ($query) use ($project, $excludeFeatureRequestId) {
                $query->where('project_id', $project->id)->where('id', '!=', $excludeFeatureRequestId);
            })
            ->with('changeSet.featureRequest:id,prompt')
            ->get();

        $result = [];
        foreach ($conflicting as $file) {
            // First match wins per path — one conflicting feature is enough
            // to warn about, no need to enumerate every other in-flight one.
            $result[$file->path] ??= [
                'feature_request_id' => $file->changeSet->featureRequest->id,
                'prompt' => $file->changeSet->featureRequest->prompt,
            ];
        }

        return $result;
    }
}
