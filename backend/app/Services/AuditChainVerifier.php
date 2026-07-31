<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Walks the audit_logs table in id order, recomputing each row's hash from
 * its own stored fields and the previous row's hash, and compares that
 * against what's actually stored. Reads via the query builder rather than
 * the AuditLog model so what's hashed is exactly the raw stored bytes
 * (metadata's literal JSON text, created_at's literal stored string) — the
 * same values AuditLogService::record() hashed at write time, not a
 * re-encoded copy that could subtly differ.
 */
class AuditChainVerifier
{
    /**
     * @return array{valid: bool, checked: int, broken_at_id: ?int}
     */
    public function verify(): array
    {
        $previousHash = null;
        $checked = 0;

        foreach (DB::table('audit_logs')->orderBy('id')->cursor() as $log) {
            $checked++;

            $expectedHash = AuditHashChain::compute(
                $previousHash,
                $log->user_id,
                $log->project_id,
                $log->team_id,
                $log->action,
                $log->metadata,
                $log->created_at,
            );

            if ($log->previous_hash !== $previousHash || $log->hash !== $expectedHash) {
                return ['valid' => false, 'checked' => $checked, 'broken_at_id' => $log->id];
            }

            $previousHash = $log->hash;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at_id' => null];
    }
}
