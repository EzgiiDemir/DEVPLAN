<?php

namespace App\Console\Commands;

use App\Services\AuditChainVerifier;
use Illuminate\Console\Command;

/**
 * Ops tooling, not an HTTP endpoint — the check spans the entire
 * audit_logs table across every user/project/team, and this app has no
 * platform-admin role to gate that kind of whole-table operation behind.
 * Run manually or on a schedule (e.g. nightly) by whoever has server/CLI
 * access, exactly like any other integrity check a real ops team runs.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit log\'s hash chain has not been tampered with';

    public function handle(AuditChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result['valid']) {
            $this->info("Audit chain verified: {$result['checked']} row(s), no tampering detected.");

            return self::SUCCESS;
        }

        $this->error("Audit chain broken at row id {$result['broken_at_id']} (checked {$result['checked']} row(s) before stopping).");

        return self::FAILURE;
    }
}
