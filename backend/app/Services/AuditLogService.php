<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A bounded, append-only event log for security-relevant actions — auth,
 * team/role changes, project deletion, the AI file-approval pipeline's three
 * gates, and Companion command/file executions relayed by the frontend. Not
 * a general-purpose activity feed (that's Phase 10's ActivityFeedService,
 * which reads existing tables) — this is a dedicated write path for events
 * that have no other durable home.
 *
 * Every row also carries a hash chained to the previous row's (see
 * AuditHashChain / AuditChainVerifier) — appending is the only way in,
 * there's no update() anywhere on this model, and altering or deleting a
 * row after the fact breaks the chain in a way that's detectable, not just
 * "assumed not to happen because nothing writes to this table."
 */
class AuditLogService
{
    public function record(?User $actor, string $action, array $metadata = [], ?Project $project = null, ?Team $team = null): AuditLog
    {
        return DB::transaction(function () use ($actor, $action, $metadata, $project, $team) {
            // lockForUpdate() closes the read-then-insert race between two
            // concurrent calls both reading the same "last" hash — SQLite
            // (only used in tests) doesn't have real row locks and just
            // no-ops this, which is fine given tests never write concurrently.
            $previousHash = AuditLog::orderByDesc('id')->lockForUpdate()->first()?->hash;

            $userId = $actor?->id;
            $projectId = $project?->id;
            $teamId = $team?->id ?? $project?->team_id;
            $createdAt = now();
            $metadataJson = json_encode($metadata);

            $hash = AuditHashChain::compute(
                $previousHash,
                $userId,
                $projectId,
                $teamId,
                $action,
                $metadataJson,
                $createdAt->toDateTimeString(),
            );

            return AuditLog::create([
                'user_id' => $userId,
                'project_id' => $projectId,
                'team_id' => $teamId,
                'action' => $action,
                'metadata' => $metadata,
                'ip_address' => request()?->ip(),
                'created_at' => $createdAt,
                'previous_hash' => $previousHash,
                'hash' => $hash,
            ]);
        });
    }
}
