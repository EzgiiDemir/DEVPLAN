<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DevEngine\QualityScanService;
use Illuminate\Http\Request;

class QualityController extends Controller
{
    public function __construct(private QualityScanService $quality) {}

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return $this->quality->currentSnapshot($project);
    }

    public function detect(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'has_package_json' => ['sometimes', 'boolean'],
            'has_composer_json' => ['sometimes', 'boolean'],
            'has_eslint_config' => ['sometimes', 'boolean'],
        ]);

        return ['commands' => $this->quality->buildScanCommands([
            'hasPackageJson' => $data['has_package_json'] ?? false,
            'hasComposerJson' => $data['has_composer_json'] ?? false,
            'hasEslintConfig' => $data['has_eslint_config'] ?? false,
        ])];
    }

    public function scan(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'npm_audit_json' => ['sometimes', 'nullable', 'string'],
            'composer_audit_json' => ['sometimes', 'nullable', 'string'],
            'eslint_json' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->quality->recordScan(
            $project,
            $data['npm_audit_json'] ?? null,
            $data['composer_audit_json'] ?? null,
            $data['eslint_json'] ?? null,
        );
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
