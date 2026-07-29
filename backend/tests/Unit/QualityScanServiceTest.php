<?php

namespace Tests\Unit;

use App\Services\DevEngine\QualityScanService;
use PHPUnit\Framework\TestCase;

class QualityScanServiceTest extends TestCase
{
    private QualityScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QualityScanService;
    }

    public function test_build_scan_commands_includes_only_what_applies(): void
    {
        $commands = $this->service->buildScanCommands([
            'hasPackageJson' => true,
            'hasEslintConfig' => true,
            'hasComposerJson' => true,
        ]);

        $this->assertSame('npm audit --json', $commands['npm_audit']);
        $this->assertSame('npx eslint . --format=json', $commands['eslint']);
        $this->assertSame('composer audit --format=json', $commands['composer_audit']);
    }

    public function test_build_scan_commands_skips_eslint_without_a_config(): void
    {
        $commands = $this->service->buildScanCommands(['hasPackageJson' => true]);

        $this->assertArrayHasKey('npm_audit', $commands);
        $this->assertArrayNotHasKey('eslint', $commands);
    }

    public function test_parses_real_shaped_npm_audit_json(): void
    {
        $json = json_encode([
            'vulnerabilities' => [
                'lodash' => [
                    'severity' => 'high',
                    'via' => [['title' => 'Prototype Pollution in lodash', 'severity' => 'high']],
                ],
                'minimist' => [
                    'severity' => 'low',
                    'via' => ['some-other-package'],
                ],
            ],
            'metadata' => ['vulnerabilities' => ['info' => 0, 'low' => 1, 'moderate' => 0, 'high' => 1, 'critical' => 0, 'total' => 2]],
        ]);

        $result = $this->service->parseNpmAudit($json);

        $this->assertSame(1, $result['high']);
        $this->assertSame(1, $result['low']);
        $this->assertCount(2, $result['vulnerabilities']);
        $this->assertSame('Prototype Pollution in lodash', $result['vulnerabilities'][0]['title']);
        // A bare-string `via` entry (no advisory object) still yields a usable title.
        $this->assertSame('some-other-package', $result['vulnerabilities'][1]['title']);
    }

    public function test_parses_real_shaped_composer_audit_json(): void
    {
        $json = json_encode([
            'advisories' => [
                'guzzlehttp/guzzle' => [
                    ['title' => 'CRLF injection', 'severity' => 'high'],
                    ['title' => 'Some other issue'], // no severity — legacy composer versions omit it
                ],
            ],
        ]);

        $result = $this->service->parseComposerAudit($json);

        $this->assertSame(1, $result['high']);
        $this->assertSame(1, $result['moderate']); // missing severity defaults to moderate, not dropped
        $this->assertCount(2, $result['vulnerabilities']);
    }

    public function test_parses_real_shaped_eslint_json(): void
    {
        $json = json_encode([
            [
                'filePath' => '/project/src/App.jsx',
                'errorCount' => 1,
                'warningCount' => 1,
                'messages' => [
                    ['ruleId' => 'no-unused-vars', 'severity' => 2, 'message' => "'x' is defined but never used.", 'line' => 5],
                    ['ruleId' => 'no-console', 'severity' => 1, 'message' => 'Unexpected console statement.', 'line' => 10],
                ],
            ],
        ]);

        $result = $this->service->parseEslintJson($json);

        $this->assertSame(1, $result['errors']);
        $this->assertSame(1, $result['warnings']);
        $this->assertCount(2, $result['issues']);
        $this->assertSame('error', $result['issues'][0]['severity']);
        $this->assertSame('warning', $result['issues'][1]['severity']);
    }

    public function test_parsers_handle_malformed_input_gracefully(): void
    {
        $this->assertSame(
            ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0, 'vulnerabilities' => []],
            $this->service->parseNpmAudit('not json'),
        );
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'issues' => []], $this->service->parseEslintJson('not json'));
    }
}
