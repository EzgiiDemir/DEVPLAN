<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

/**
 * A bounded, append-only event log for security-relevant actions — auth,
 * team/role changes, project deletion, the AI file-approval pipeline's three
 * gates, and Companion command/file executions relayed by the frontend. Not
 * a general-purpose activity feed (that's Phase 10's ActivityFeedService,
 * which reads existing tables) — this is a dedicated write path for events
 * that have no other durable home.
 */
class AuditLogService
{
    public function record(?User $actor, string $action, array $metadata = [], ?Project $project = null, ?Team $team = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'project_id' => $project?->id,
            'team_id' => $team?->id ?? $project?->team_id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
