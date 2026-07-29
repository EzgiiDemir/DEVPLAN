<?php

namespace App\Services\DevEngine;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\User;

/**
 * Detects which test framework a project uses and builds the right CLI
 * invocation, then parses whatever that invocation produces. Every parser
 * here reads a tool's own structured output format (JUnit XML, Jest/Vitest
 * --json, Istanbul/Clover/coverage.py coverage reports) — never regex
 * against human-readable console text, which changes between tool versions
 * and is never reliable to scrape.
 */
class TestRunnerService
{
    private const RESULT_PATH_XML = '.devplan/test-results.xml';

    private const RESULT_PATH_JSON = '.devplan/test-results.json';

    /**
     * Pure function: given raw signals the frontend already read via
     * Companion (file existence/content), decides which framework applies
     * and exactly what command to run. No I/O here — testable with plain
     * arrays.
     *
     * @param  array{packageJsonContent?: ?string, composerJsonContent?: ?string, hasPytestConfig?: bool}  $signals
     */
    public function detectFramework(array $signals): array
    {
        $none = ['framework' => null, 'runCommand' => null, 'resultFilePath' => null, 'coverageFilePath' => null];

        $packageJson = $signals['packageJsonContent'] ?? null;
        if ($packageJson) {
            $decoded = json_decode($packageJson, true);
            $deps = is_array($decoded)
                ? array_merge($decoded['dependencies'] ?? [], $decoded['devDependencies'] ?? [])
                : [];

            if (array_key_exists('vitest', $deps)) {
                return [
                    'framework' => 'vitest',
                    // Coverage tools default to clover/lcov/text, not the
                    // istanbul "json-summary" shape parseIstanbulCoverageSummary()
                    // reads — it has to be requested explicitly (confirmed
                    // against a real Jest run; Vitest's coverage.reporter
                    // option follows the same istanbul reporter names).
                    'runCommand' => 'npx vitest run --reporter=json --outputFile='.self::RESULT_PATH_JSON.' --coverage --coverage.reporter=json-summary',
                    'resultFilePath' => self::RESULT_PATH_JSON,
                    'coverageFilePath' => 'coverage/coverage-summary.json',
                ];
            }

            if (array_key_exists('jest', $deps)) {
                return [
                    'framework' => 'jest',
                    'runCommand' => 'npx jest --json --outputFile='.self::RESULT_PATH_JSON.' --coverage --coverageReporters=json-summary',
                    'resultFilePath' => self::RESULT_PATH_JSON,
                    'coverageFilePath' => 'coverage/coverage-summary.json',
                ];
            }
        }

        $composerJson = $signals['composerJsonContent'] ?? null;
        if ($composerJson) {
            $decoded = json_decode($composerJson, true);
            $deps = is_array($decoded)
                ? array_merge($decoded['require'] ?? [], $decoded['require-dev'] ?? [])
                : [];

            if (array_key_exists('phpunit/phpunit', $deps)) {
                return [
                    'framework' => 'phpunit',
                    // Laravel's `artisan test` forwards unrecognized options
                    // straight to the underlying PHPUnit process.
                    'runCommand' => 'php artisan test --log-junit='.self::RESULT_PATH_XML.' --coverage-clover=.devplan/coverage.xml',
                    'resultFilePath' => self::RESULT_PATH_XML,
                    'coverageFilePath' => '.devplan/coverage.xml',
                ];
            }
        }

        if (! empty($signals['hasPytestConfig'])) {
            return [
                'framework' => 'pytest',
                // Bare "pytest" isn't in the Companion command allowlist;
                // "python -m pytest" is, since it starts with "python ".
                'runCommand' => 'python -m pytest --junit-xml='.self::RESULT_PATH_XML.' --cov --cov-report=json:.devplan/coverage.json',
                'resultFilePath' => self::RESULT_PATH_XML,
                'coverageFilePath' => '.devplan/coverage.json',
            ];
        }

        return $none;
    }

    public function recordRun(
        Project $project,
        User $user,
        string $framework,
        ?string $resultFileContent,
        int $exitCode,
        ?string $coverageFileContent = null,
        ?FeatureRequest $trigger = null,
    ): TestRun {
        $parsed = $resultFileContent ? $this->parseResults($framework, $resultFileContent) : null;
        $coveragePercent = $coverageFileContent ? $this->parseCoverage($framework, $coverageFileContent) : null;

        // No parseable result file at all (e.g. a syntax error stopped the
        // suite before it could even run) is a real, distinct outcome from
        // "ran and some tests failed" — surfaced as 'error', not 'failed'.
        $status = $parsed === null ? 'error' : ($parsed['failed'] > 0 ? 'failed' : 'passed');

        return $project->testRuns()->create([
            'user_id' => $user->id,
            'feature_request_id' => $trigger?->id,
            'framework' => $framework,
            'status' => $status,
            'passed_count' => $parsed['passed'] ?? 0,
            'failed_count' => $parsed['failed'] ?? 0,
            'total_count' => $parsed['total'] ?? 0,
            'duration_ms' => $parsed['duration_ms'] ?? null,
            'failures' => $parsed['failures'] ?? [],
            'coverage_percent' => $coveragePercent,
        ]);
    }

    private function parseResults(string $framework, string $content): ?array
    {
        return match ($framework) {
            'phpunit', 'pytest' => $this->parseJunitXml($content),
            'jest' => $this->parseJestJson($content),
            'vitest' => $this->parseVitestJson($content),
            default => null,
        };
    }

    private function parseCoverage(string $framework, string $content): ?float
    {
        return match ($framework) {
            'phpunit' => $this->parsePhpCloverCoverage($content),
            'pytest' => $this->parsePytestCovJson($content),
            'jest', 'vitest' => $this->parseIstanbulCoverageSummary($content),
            default => null,
        };
    }

    /**
     * JUnit XML — the schema PHPUnit's --log-junit and pytest's --junit-xml
     * both emit (testsuite(s) > testcase[name,classname,time] >
     * failure|error[message]).
     */
    public function parseJunitXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return ['passed' => 0, 'failed' => 0, 'total' => 0, 'duration_ms' => null, 'failures' => []];
        }

        $testcases = $doc->xpath('//testcase') ?: [];
        $failures = [];
        $failedCount = 0;
        $totalTimeSeconds = 0.0;

        foreach ($testcases as $tc) {
            $attrs = $tc->attributes();
            $totalTimeSeconds += (float) ($attrs->time ?? 0);

            $failureNode = isset($tc->failure) ? $tc->failure : (isset($tc->error) ? $tc->error : null);
            if ($failureNode !== null) {
                $failedCount++;
                $failureAttrs = $failureNode->attributes();
                $failures[] = [
                    'name' => (string) ($attrs->name ?? 'unknown'),
                    'message' => (string) ($failureAttrs->message ?? trim((string) $failureNode)) ?: 'Test failed.',
                    'file' => (string) ($attrs->classname ?? $attrs->file ?? ''),
                ];
            }
        }

        $total = count($testcases);

        return [
            'passed' => $total - $failedCount,
            'failed' => $failedCount,
            'total' => $total,
            'duration_ms' => (int) round($totalTimeSeconds * 1000),
            'failures' => $failures,
        ];
    }

    /**
     * Shared shape between Jest's --json and Vitest's --reporter=json —
     * Vitest's JSON reporter deliberately mirrors Jest's for tooling
     * compatibility; only per-file duration fields differ, handled by the
     * caller-supplied $durationExtractor.
     */
    private function parseJestLikeJson(string $json, callable $durationExtractor): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return ['passed' => 0, 'failed' => 0, 'total' => 0, 'duration_ms' => null, 'failures' => []];
        }

        $failures = [];
        $durationMs = 0;
        $hasDuration = false;

        foreach ($data['testResults'] ?? [] as $fileResult) {
            $file = $fileResult['name'] ?? '';

            $fileDuration = $durationExtractor($fileResult);
            if ($fileDuration !== null) {
                $durationMs += $fileDuration;
                $hasDuration = true;
            }

            foreach ($fileResult['assertionResults'] ?? [] as $assertion) {
                if (($assertion['status'] ?? '') === 'failed') {
                    $failures[] = [
                        'name' => $assertion['fullName'] ?? $assertion['title'] ?? 'unknown',
                        'message' => implode("\n", $assertion['failureMessages'] ?? []) ?: 'Test failed.',
                        'file' => $file,
                    ];
                }
            }
        }

        return [
            'passed' => (int) ($data['numPassedTests'] ?? 0),
            'failed' => (int) ($data['numFailedTests'] ?? 0),
            'total' => (int) ($data['numTotalTests'] ?? 0),
            'duration_ms' => $hasDuration ? $durationMs : null,
            'failures' => $failures,
        ];
    }

    public function parseJestJson(string $json): array
    {
        return $this->parseJestLikeJson(
            $json,
            fn (array $fr) => isset($fr['perfStats']['runtime']) ? (int) $fr['perfStats']['runtime'] : null,
        );
    }

    public function parseVitestJson(string $json): array
    {
        return $this->parseJestLikeJson($json, function (array $fr) {
            if (! isset($fr['startTime'], $fr['endTime'])) {
                return null;
            }

            return max(0, (int) $fr['endTime'] - (int) $fr['startTime']);
        });
    }

    /**
     * Istanbul's coverage-summary.json — shared format between Jest's own
     * coverage reporter and Vitest's v8/istanbul coverage providers.
     */
    public function parseIstanbulCoverageSummary(string $json): ?float
    {
        $data = json_decode($json, true);
        $pct = $data['total']['lines']['pct'] ?? null;

        return is_numeric($pct) ? round((float) $pct, 2) : null;
    }

    /**
     * PHPUnit's Clover XML (--coverage-clover) — the project-level
     * aggregate is the <metrics> element that's a *direct* child of
     * <project>, not one of the per-file ones nested under <file>.
     */
    public function parsePhpCloverCoverage(string $xml): ?float
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false || ! isset($doc->project->metrics)) {
            return null;
        }

        $metrics = $doc->project->metrics->attributes();
        $statements = (float) ($metrics->statements ?? 0);
        $covered = (float) ($metrics->coveredstatements ?? 0);

        if ($statements <= 0) {
            return null;
        }

        return round(($covered / $statements) * 100, 2);
    }

    /**
     * coverage.py's JSON report (pytest-cov's --cov-report=json).
     */
    public function parsePytestCovJson(string $json): ?float
    {
        $data = json_decode($json, true);
        $pct = $data['totals']['percent_covered'] ?? null;

        return is_numeric($pct) ? round((float) $pct, 2) : null;
    }
}
