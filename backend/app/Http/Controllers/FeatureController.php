<?php

namespace App\Http\Controllers;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Services\DevEngine\ChangeSetService;
use App\Services\DevEngine\CheckpointService;
use App\Services\DevEngine\FeatureAgentService;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function __construct(
        private FeatureAgentService $agent,
        private ChangeSetService $changeSets,
        private CheckpointService $checkpoints,
    ) {}

    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return $project->featureRequests()->latest()->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        $featureRequest = $this->agent->createAndPlan($project, $request->user(), $data['prompt']);

        return response()->json($featureRequest, 201);
    }

    public function show(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorizedFeatureRequest($request, $project, $featureRequest);

        return $featureRequest->load('changeSet.files');
    }

    public function approvePlan(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorizedFeatureRequest($request, $project, $featureRequest);

        $data = $request->validate([
            'approved_paths' => ['required', 'array'],
            'approved_paths.*' => ['string'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $result = $this->agent->approvePlan($changeSet, $data['approved_paths']);

        return response()->json($result);
    }

    public function generate(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorizedFeatureRequest($request, $project, $featureRequest);

        $data = $request->validate([
            'files' => ['sometimes', 'array'],
            'files.*.path' => ['required_with:files', 'string'],
            'files.*.content' => ['required_with:files', 'string'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $currentContentByPath = collect($data['files'] ?? [])
            ->mapWithKeys(fn ($f) => [$f['path'] => $f['content']])
            ->all();

        $updated = $this->agent->generateContent($changeSet, $currentContentByPath);

        return response()->json($updated);
    }

    public function approveDiff(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorizedFeatureRequest($request, $project, $featureRequest);

        $data = $request->validate([
            'approved_paths' => ['required', 'array'],
            'approved_paths.*' => ['string'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $this->changeSets->approveDiff($changeSet, $data['approved_paths']);

        return response()->json($changeSet->load('files'));
    }

    /**
     * Called after the frontend has already written the approved files to
     * disk via Companion and run the before/after git commits — this just
     * durably records what happened. This service never touches git or the
     * user's disk itself.
     */
    public function apply(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorizedFeatureRequest($request, $project, $featureRequest);

        $data = $request->validate([
            'applied_paths' => ['required', 'array'],
            'applied_paths.*' => ['string'],
            'before' => ['required', 'array'],
            'before.hash' => ['required', 'string', 'regex:/^[0-9a-f]{7,40}$/i'],
            'before.message' => ['required', 'string', 'max:255'],
            'after' => ['required', 'array'],
            'after.hash' => ['required', 'string', 'regex:/^[0-9a-f]{7,40}$/i'],
            'after.message' => ['required', 'string', 'max:255'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $this->checkpoints->record($project, $featureRequest, $data['before']['hash'], $data['before']['message']);
        $this->changeSets->markApplied($changeSet, $data['applied_paths']);
        $after = $this->checkpoints->record($project, $featureRequest, $data['after']['hash'], $data['after']['message']);

        return response()->json([
            'feature_request' => $featureRequest->fresh(),
            'checkpoint' => $after,
        ]);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }

    private function authorizedFeatureRequest(Request $request, Project $project, FeatureRequest $featureRequest): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($featureRequest->project_id === $project->id, 404);
    }
}
