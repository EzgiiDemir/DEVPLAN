<?php

namespace App\Services\DevEngine;

use App\Models\Project;

/**
 * Security and code-quality data come from each tool's own real,
 * already-existing structured output — `npm audit --json` / `composer
 * audit --format=json` for security, `eslint --format=json` for code
 * quality — never a custom scanner. Performance issues are deliberately
 * not implemented: there is no reliable, zero-extra-infrastructure signal
 * across arbitrary generated stacks, so the dashboard says so explicitly
 * rather than fabricating a number. Stored as a single current-state blob
 * on `projects` (same precedent as `workspace_state`), not a history table —
 * this is "what does the project look like right now," not a timeline.
 */
class QualityScanService
{
    /**
     * Pure function: which commands actually apply, given what manifests/
     * configs exist. Single source of truth for the exact command strings —
     * the frontend only ever executes what this returns, never invents its
     * own invocation.
     *
     * @param  array{hasPackageJson?: bool, hasComposerJson?: bool, hasEslintConfig?: bool}  $signals
     */
    public function buildScanCommands(array $signals): array
    {
        $commands = [];

        if (! empty($signals['hasPackageJson'])) {
            $commands['npm_audit'] = 'npm audit --json';

            if (! empty($signals['hasEslintConfig'])) {
                $commands['eslint'] = 'npx eslint . --format=json';
            }
        }

        if (! empty($signals['hasComposerJson'])) {
            $commands['composer_audit'] = 'composer audit --format=json';
        }

        return $commands;
    }

    public function recordScan(Project $project, ?string $npmAuditJson, ?string $composerAuditJson, ?string $eslintJson): array
    {
        $security = ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0, 'vulnerabilities' => []];

        if ($npmAuditJson) {
            $security = $this->mergeSecurity($security, $this->parseNpmAudit($npmAuditJson));
        }
        if ($composerAuditJson) {
            $security = $this->mergeSecurity($security, $this->parseComposerAudit($composerAuditJson));
        }

        $codeQuality = $eslintJson
            ? $this->parseEslintJson($eslintJson)
            : ['errors' => 0, 'warnings' => 0, 'issues' => []];

        $project->update([
            'quality_snapshot' => ['security' => $security, 'code_quality' => $codeQuality],
            'quality_scanned_at' => now(),
        ]);

        return $this->currentSnapshot($project);
    }

    public function currentSnapshot(Project $project): array
    {
        $snapshot = $project->quality_snapshot;
        $latestWithCoverage = $project->testRuns()->whereNotNull('coverage_percent')->first();

        return [
            'security' => $snapshot['security'] ?? null,
            'code_quality' => $snapshot['code_quality'] ?? null,
            'coverage_percent' => $latestWithCoverage?->coverage_percent,
            'scanned_at' => $project->quality_scanned_at,
            // Explicitly absent rather than fabricated — see class docblock.
            'performance' => null,
        ];
    }

    /**
     * npm's real `audit --json` shape: {vulnerabilities: {pkgName: {severity,
     * via: [...]}}, metadata: {vulnerabilities: {critical, high, moderate, low, ...}}}.
     */
    public function parseNpmAudit(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0, 'vulnerabilities' => []];
        }

        $counts = $data['metadata']['vulnerabilities'] ?? [];
        $vulnerabilities = [];

        foreach ($data['vulnerabilities'] ?? [] as $name => $vuln) {
            $viaFirst = $vuln['via'][0] ?? null;
            $title = is_array($viaFirst) ? ($viaFirst['title'] ?? $name) : ($viaFirst ?? $name);

            $vulnerabilities[] = [
                'name' => $name,
                'severity' => $vuln['severity'] ?? 'unknown',
                'title' => $title,
            ];
        }

        return [
            'critical' => (int) ($counts['critical'] ?? 0),
            'high' => (int) ($counts['high'] ?? 0),
            'moderate' => (int) ($counts['moderate'] ?? 0),
            'low' => (int) ($counts['low'] ?? 0),
            'vulnerabilities' => $vulnerabilities,
        ];
    }

    /**
     * Composer's real `audit --format=json` shape: {advisories: {"vendor/pkg":
     * [{title, severity, ...}]}}. Older Composer versions omit "severity"
     * entirely — those advisories are counted under "moderate" rather than
     * silently dropped.
     */
    public function parseComposerAudit(string $json): array
    {
        $data = json_decode($json, true);
        $counts = ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0];
        $vulnerabilities = [];

        foreach ($data['advisories'] ?? [] as $package => $packageAdvisories) {
            foreach ($packageAdvisories as $advisory) {
                $severity = strtolower($advisory['severity'] ?? 'moderate');
                if (! isset($counts[$severity])) {
                    $severity = 'moderate';
                }
                $counts[$severity]++;

                $vulnerabilities[] = [
                    'name' => $package,
                    'severity' => $severity,
                    'title' => $advisory['title'] ?? $package,
                ];
            }
        }

        return array_merge($counts, ['vulnerabilities' => $vulnerabilities]);
    }

    private function mergeSecurity(array $a, array $b): array
    {
        return [
            'critical' => $a['critical'] + $b['critical'],
            'high' => $a['high'] + $b['high'],
            'moderate' => $a['moderate'] + $b['moderate'],
            'low' => $a['low'] + $b['low'],
            'vulnerabilities' => array_merge($a['vulnerabilities'], $b['vulnerabilities']),
        ];
    }

    /**
     * ESLint's real `--format=json` shape: an array of
     * {filePath, messages: [{ruleId, severity, message, line}], errorCount, warningCount}.
     */
    public function parseEslintJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return ['errors' => 0, 'warnings' => 0, 'issues' => []];
        }

        $errors = 0;
        $warnings = 0;
        $issues = [];

        foreach ($data as $fileResult) {
            $errors += (int) ($fileResult['errorCount'] ?? 0);
            $warnings += (int) ($fileResult['warningCount'] ?? 0);

            foreach ($fileResult['messages'] ?? [] as $msg) {
                $issues[] = [
                    'file' => $fileResult['filePath'] ?? '',
                    'line' => $msg['line'] ?? null,
                    'message' => $msg['message'] ?? '',
                    'severity' => (int) ($msg['severity'] ?? 1) === 2 ? 'error' : 'warning',
                    'rule' => $msg['ruleId'] ?? null,
                ];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'issues' => $issues];
    }
}
