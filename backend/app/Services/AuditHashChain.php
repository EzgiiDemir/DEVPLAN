<?php

namespace App\Services;

/**
 * The single formula behind the tamper-evident audit log: each row's hash
 * covers its own fields *and* the previous row's hash, so altering or
 * deleting any row breaks every hash after it in the chain — detectable by
 * AuditChainVerifier without needing a separate signature store. Shared by
 * AuditLogService (writes) and AuditChainVerifier (reads) so the formula
 * only exists in one place; the migration that backfilled existing rows
 * keeps its own inline copy deliberately, since migrations shouldn't depend
 * on application classes that might move or change later.
 */
class AuditHashChain
{
    public static function compute(
        ?string $previousHash,
        ?int $userId,
        ?int $projectId,
        ?int $teamId,
        string $action,
        ?string $metadataJson,
        ?string $createdAt,
    ): string {
        return hash('sha256', implode('|', [
            $previousHash ?? '',
            $userId ?? '',
            $projectId ?? '',
            $teamId ?? '',
            $action,
            $metadataJson ?? '',
            $createdAt ?? '',
        ]));
    }
}
