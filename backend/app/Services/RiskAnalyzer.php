<?php

namespace App\Services;

/**
 * Attaches a risk label to an AI-proposed file change so the existing
 * plan/diff approval screens show it, not a new approval flow — a human
 * still has to check the box either way; this just tells them which boxes
 * deserve a closer look before they do.
 */
class RiskAnalyzer
{
    private const HIGH_RISK_PATTERNS = [
        '/(^|\/)\.env/i',
        '/(^|\/)migrations\//i',
        '/(^|\/)\.git\//i',
        '/(^|\/)\.ssh\//i',
        '/(^|\/)id_rsa/i',
    ];

    private const MEDIUM_RISK_PATTERNS = [
        '/(^|\/)(composer|package)\.lock$/i',
        '/(^|\/)package-lock\.json$/i',
        '/(^|\/)config\//i',
        '/(^|\/)docker-compose/i',
        '/(^|\/)dockerfile$/i',
    ];

    /**
     * $content is optional and additive (Subsystem 7 — Static Security
     * Scan): every existing two-argument call site is unaffected. When
     * given, a file whose generated content trips ContentSecurityScanner is
     * escalated to 'high' regardless of what the path alone would classify
     * as — a plain-looking file can still contain a hardcoded secret or a
     * raw SQL string.
     */
    public function classifyFile(string $path, string $action, ?string $content = null): string
    {
        if ($action === 'delete') {
            return 'high';
        }

        if ($content !== null && (new ContentSecurityScanner)->scan($content) !== []) {
            return 'high';
        }

        foreach (self::HIGH_RISK_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return 'high';
            }
        }

        foreach (self::MEDIUM_RISK_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return 'medium';
            }
        }

        return 'low';
    }
}
