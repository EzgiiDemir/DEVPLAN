<?php

namespace App\Services\DevEngine;

use App\Models\ChangeSet;

/**
 * The second approval gate (after plan approval in FeatureAgentService):
 * the user reviews the AI-generated diffs and decides which ones actually
 * get applied. This service only ever updates `change_set_files` rows — the
 * real disk writes happen in the paired browser tab via Companion, and the
 * frontend reports back which paths it successfully wrote via markApplied().
 */
class ChangeSetService
{
    /**
     * @param  array<int, string>  $approvedPaths
     */
    public function approveDiff(ChangeSet $changeSet, array $approvedPaths): void
    {
        $changeSet->files()
            ->where('plan_approved', true)
            ->whereIn('path', $approvedPaths)
            ->update(['diff_approved' => true]);
    }

    /**
     * @param  array<int, string>  $appliedPaths
     */
    public function markApplied(ChangeSet $changeSet, array $appliedPaths): void
    {
        $changeSet->files()
            ->where('diff_approved', true)
            ->whereIn('path', $appliedPaths)
            ->update(['applied' => true]);

        $remaining = $changeSet->files()
            ->where('diff_approved', true)
            ->where('applied', false)
            ->count();

        if ($remaining === 0) {
            $changeSet->featureRequest->update(['status' => 'applied']);
        }
    }
}
