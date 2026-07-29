<?php

namespace Tests\Unit;

use App\Services\DevEngine\TestRunnerService;
use PHPUnit\Framework\TestCase;

class TestRunnerServiceTest extends TestCase
{
    private TestRunnerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestRunnerService;
    }

    // ---- detectFramework ----

    public function test_detects_vitest_over_jest_when_package_json_has_vitest(): void
    {
        $result = $this->service->detectFramework([
            'packageJsonContent' => json_encode(['devDependencies' => ['vitest' => '^1.0.0', 'jest' => '^29.0.0']]),
        ]);

        $this->assertSame('vitest', $result['framework']);
        $this->assertStringContainsString('--reporter=json', $result['runCommand']);
    }

    public function test_detects_jest_when_only_jest_present(): void
    {
        $result = $this->service->detectFramework([
            'packageJsonContent' => json_encode(['devDependencies' => ['jest' => '^29.0.0']]),
        ]);

        $this->assertSame('jest', $result['framework']);
        $this->assertStringContainsString('--json', $result['runCommand']);
    }

    public function test_detects_phpunit_from_composer_json(): void
    {
        $result = $this->service->detectFramework([
            'composerJsonContent' => json_encode(['require-dev' => ['phpunit/phpunit' => '^11.0']]),
        ]);

        $this->assertSame('phpunit', $result['framework']);
        $this->assertStringContainsString('php artisan test', $result['runCommand']);
        $this->assertStringContainsString('--log-junit=', $result['runCommand']);
    }

    public function test_detects_pytest_and_uses_python_dash_m_form_not_bare_pytest(): void
    {
        $result = $this->service->detectFramework(['hasPytestConfig' => true]);

        $this->assertSame('pytest', $result['framework']);
        // Must start with "python -m pytest", not bare "pytest ..." — the
        // Companion allowlist has no bare "pytest" prefix.
        $this->assertStringStartsWith('python -m pytest', $result['runCommand']);
    }

    public function test_returns_null_framework_when_nothing_detected(): void
    {
        $result = $this->service->detectFramework([]);

        $this->assertNull($result['framework']);
        $this->assertNull($result['runCommand']);
    }

    // ---- parseJunitXml (shared by phpunit/pytest) ----

    public function test_parses_junit_xml_with_a_real_failure(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Tests" tests="3" failures="1" errors="0" time="0.456">
    <testcase name="test_it_adds_numbers" classname="Tests\MathTest" time="0.010"/>
    <testcase name="test_it_subtracts_numbers" classname="Tests\MathTest" time="0.020">
      <failure message="Failed asserting that 3 matches expected 4.">Stack trace here</failure>
    </testcase>
    <testcase name="test_it_multiplies" classname="Tests\MathTest" time="0.015"/>
  </testsuite>
</testsuites>
XML;

        $result = $this->service->parseJunitXml($xml);

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(2, $result['passed']);
        $this->assertSame(45, $result['duration_ms']);
        $this->assertCount(1, $result['failures']);
        $this->assertSame('test_it_subtracts_numbers', $result['failures'][0]['name']);
        $this->assertStringContainsString('Failed asserting', $result['failures'][0]['message']);
        $this->assertSame('Tests\MathTest', $result['failures'][0]['file']);
    }

    public function test_parses_junit_xml_error_nodes_as_failures_too(): void
    {
        $xml = <<<'XML'
<testsuites>
  <testsuite name="Tests">
    <testcase name="test_crashes" classname="Tests\CrashTest" time="0.001">
      <error message="RuntimeException: something broke">trace</error>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $result = $this->service->parseJunitXml($xml);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('RuntimeException', $result['failures'][0]['message']);
    }

    public function test_parses_malformed_junit_xml_gracefully(): void
    {
        $result = $this->service->parseJunitXml('not xml at all');

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['failures']);
    }

    // ---- parseJestJson ----

    public function test_parses_real_shaped_jest_json_output(): void
    {
        $json = json_encode([
            'numTotalTests' => 3,
            'numPassedTests' => 2,
            'numFailedTests' => 1,
            'testResults' => [
                [
                    'name' => '/project/src/math.test.js',
                    'perfStats' => ['runtime' => 120],
                    'assertionResults' => [
                        ['title' => 'adds', 'fullName' => 'Math adds', 'status' => 'passed', 'failureMessages' => []],
                        ['title' => 'subtracts', 'fullName' => 'Math subtracts', 'status' => 'failed', 'failureMessages' => ['Expected 4, got 3']],
                        ['title' => 'multiplies', 'fullName' => 'Math multiplies', 'status' => 'passed', 'failureMessages' => []],
                    ],
                ],
            ],
        ]);

        $result = $this->service->parseJestJson($json);

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(120, $result['duration_ms']);
        $this->assertCount(1, $result['failures']);
        $this->assertSame('Math subtracts', $result['failures'][0]['name']);
        $this->assertSame('/project/src/math.test.js', $result['failures'][0]['file']);
        $this->assertStringContainsString('Expected 4', $result['failures'][0]['message']);
    }

    // ---- parseVitestJson ----

    public function test_parses_real_shaped_vitest_json_output(): void
    {
        $json = json_encode([
            'numTotalTests' => 2,
            'numPassedTests' => 1,
            'numFailedTests' => 1,
            'testResults' => [
                [
                    'name' => '/project/src/util.test.ts',
                    'startTime' => 1000,
                    'endTime' => 1080,
                    'assertionResults' => [
                        ['title' => 'works', 'fullName' => 'util works', 'status' => 'passed', 'failureMessages' => []],
                        ['title' => 'fails', 'fullName' => 'util fails', 'status' => 'failed', 'failureMessages' => ['AssertionError: expected true']],
                    ],
                ],
            ],
        ]);

        $result = $this->service->parseVitestJson($json);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(80, $result['duration_ms']);
        $this->assertSame('util fails', $result['failures'][0]['name']);
    }

    // ---- coverage parsers ----

    public function test_parses_istanbul_coverage_summary(): void
    {
        $json = json_encode(['total' => ['lines' => ['total' => 100, 'covered' => 87, 'pct' => 87.5]]]);

        $this->assertSame(87.5, $this->service->parseIstanbulCoverageSummary($json));
    }

    public function test_parses_php_clover_coverage_from_project_level_metrics_only(): void
    {
        $xml = <<<'XML'
<coverage generated="123">
  <project timestamp="123">
    <metrics files="2" statements="300" coveredstatements="255" methods="20" coveredmethods="18"/>
    <file name="Foo.php">
      <metrics statements="10" coveredstatements="1"/>
    </file>
  </project>
</coverage>
XML;

        $pct = $this->service->parsePhpCloverCoverage($xml);

        // Must use the project-level metrics (255/300 = 85%), not the
        // per-file <file><metrics> nested one (1/10 = 10%).
        $this->assertSame(85.0, $pct);
    }

    public function test_parses_pytest_cov_json(): void
    {
        $json = json_encode(['totals' => ['covered_lines' => 450, 'num_statements' => 500, 'percent_covered' => 90.0]]);

        $this->assertSame(90.0, $this->service->parsePytestCovJson($json));
    }

    public function test_coverage_parsers_return_null_when_data_missing(): void
    {
        $this->assertNull($this->service->parseIstanbulCoverageSummary('{}'));
        $this->assertNull($this->service->parsePhpCloverCoverage('<coverage></coverage>'));
        $this->assertNull($this->service->parsePytestCovJson('{}'));
    }
}
