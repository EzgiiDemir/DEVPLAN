<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TestRun;
use App\Services\DevEngine\MayaChatService;
use App\Services\DevEngine\TestRunnerService;
use Illuminate\Http\Request;

class TestingController extends Controller
{
    public function __construct(
        private TestRunnerService $testRunner,
        private MayaChatService $maya,
    ) {}

    public function detect(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'package_json_content' => ['sometimes', 'nullable', 'string'],
            'composer_json_content' => ['sometimes', 'nullable', 'string'],
            'has_pytest_config' => ['sometimes', 'boolean'],
        ]);

        return $this->testRunner->detectFramework([
            'packageJsonContent' => $data['package_json_content'] ?? null,
            'composerJsonContent' => $data['composer_json_content'] ?? null,
            'hasPytestConfig' => $data['has_pytest_config'] ?? false,
        ]);
    }

    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return $project->testRuns()->limit(20)->get();
    }

    public function record(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'framework' => ['required', 'string', 'in:jest,vitest,phpunit,pytest'],
            'result_file_content' => ['sometimes', 'nullable', 'string'],
            'exit_code' => ['required', 'integer'],
            'coverage_file_content' => ['sometimes', 'nullable', 'string'],
        ]);

        $testRun = $this->testRunner->recordRun(
            $project,
            $request->user(),
            $data['framework'],
            $data['result_file_content'] ?? null,
            $data['exit_code'],
            $data['coverage_file_content'] ?? null,
        );

        return response()->json($testRun, 201);
    }

    public function generate(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate(['path' => ['required', 'string', 'max:1000']]);

        $prompt = sprintf(
            'Generate comprehensive unit tests for %s, covering its main behaviors and realistic edge cases.',
            $data['path'],
        );

        $result = $this->maya->handleDirectFeatureAction($project, $request->user(), 'test', $prompt, $data['path']);

        return response()->json($result, 201);
    }

    public function suggestFix(Request $request, Project $project, TestRun $testRun)
    {
        $this->authorizeProject($request, $project);
        abort_unless($testRun->project_id === $project->id, 404);

        $data = $request->validate(['failure_index' => ['required', 'integer', 'min:0']]);
        $failure = $testRun->failures[$data['failure_index']] ?? null;
        abort_unless($failure, 404);

        $prompt = sprintf(
            "Fix this failing test.\nTest: %s\nFile: %s\nError: %s",
            $failure['name'] ?? 'unknown',
            $failure['file'] ?? 'unknown',
            $failure['message'] ?? 'no message',
        );

        // Only a real path for Jest/Vitest failures (a real file location);
        // for PHPUnit/pytest it's a test classname, which simply won't match
        // any indexed file and is silently ignored by relatedFiles() — no
        // special-casing needed, that's already the designed fallback.
        $result = $this->maya->handleDirectFeatureAction($project, $request->user(), 'fix', $prompt, $failure['file'] ?? null);

        return response()->json($result, 201);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
