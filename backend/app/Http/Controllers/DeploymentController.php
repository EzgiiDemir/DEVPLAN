<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\DevEngine\DeploymentAnalyzerService;
use App\Services\DevEngine\DeploymentService;
use App\Services\DevEngine\MayaChatService;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function __construct(
        private DeploymentAnalyzerService $analyzer,
        private DeploymentService $deployments,
        private MayaChatService $maya,
    ) {}

    public function analyze(Request $request, Project $project)
    {
        // 'act', not 'view' — this triggers a real AI summary call, and
        // Viewers should never trigger AI actions even read-adjacent ones.
        $this->authorize('act', $project);

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:vercel,railway,render,docker,aws_amplify'],
            'has_vercel_json' => ['sometimes', 'boolean'],
            'has_railway_json' => ['sometimes', 'boolean'],
            'has_amplify_yml' => ['sometimes', 'boolean'],
            'has_dockerfile' => ['sometimes', 'boolean'],
        ]);

        return $this->analyzer->analyze($project, [
            'platform' => $data['platform'],
            'hasVercelJson' => $data['has_vercel_json'] ?? false,
            'hasRailwayJson' => $data['has_railway_json'] ?? false,
            'hasAmplifyYml' => $data['has_amplify_yml'] ?? false,
            'hasDockerfile' => $data['has_dockerfile'] ?? false,
        ]);
    }

    public function generateConfig(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:vercel,railway,render,docker,aws_amplify'],
        ]);

        $prompt = sprintf(
            'Add a %s configured for deploying this project to %s.',
            $data['path'],
            $data['platform'],
        );

        $result = $this->maya->handleDirectFeatureAction($project, $request->user(), 'deploy_config', $prompt, $data['path']);

        return response()->json($result, 201);
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $project->deployments()->with('checkpoint')->limit(20)->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:vercel,railway,render,docker,aws_amplify'],
        ]);

        $deployment = $this->deployments->start($project, $request->user(), $data['platform']);

        return response()->json($deployment, 201);
    }

    public function update(Request $request, Project $project, Deployment $deployment)
    {
        $this->authorize('act', $project);
        abort_unless($deployment->project_id === $project->id, 404);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:preparing,building,deploying,success,failed'],
            'log_output' => ['sometimes', 'nullable', 'string'],
            'git_commit_hash' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{7,40}$/i'],
            'live_url' => ['sometimes', 'nullable', 'url'],
            'error_message' => ['sometimes', 'nullable', 'string'],
            'companion_process_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (in_array($data['status'], ['success', 'failed'], true)) {
            $updated = $this->deployments->finish(
                $deployment,
                $data['status'] === 'success',
                $data['git_commit_hash'] ?? null,
                $data['live_url'] ?? null,
                $data['log_output'] ?? '',
                $data['error_message'] ?? null,
            );
        } else {
            $updated = $this->deployments->recordProgress(
                $deployment,
                $data['status'],
                $data['log_output'] ?? null,
                $data['companion_process_id'] ?? null,
            );
        }

        return response()->json($updated);
    }

    /**
     * A real, server-side check of whether the deployment's own reported
     * live_url actually responds — separate from the CLI-exit-code-based
     * 'success' status, which only means the deploy tool itself didn't
     * error, not that the app is actually serving traffic.
     */
    public function checkHealth(Request $request, Project $project, Deployment $deployment)
    {
        // 'act', not 'view' — this persists a new health_status/
        // last_health_checked_at, the same mutate-vs-read-only line every
        // other endpoint here draws.
        $this->authorize('act', $project);
        abort_unless($deployment->project_id === $project->id, 404);

        return response()->json($this->deployments->checkHealth($deployment));
    }
}
