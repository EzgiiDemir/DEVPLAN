<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CorrelationId;
use App\Jobs\GenerateFeatureContentJob;
use App\Jobs\GenerateFeaturePlanJob;
use App\Models\AiJob;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Services\AuditLogService;
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
        private AuditLogService $audit,
    ) {}

    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $project->featureRequests()->latest()->get();
    }

    /**
     * Returns 202 + a job id immediately — createAndPlan() makes an AI call,
     * which used to block this request for however long that call takes.
     * The frontend polls GET /ai-jobs/{id} until the job resolves.
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'type' => 'feature_plan',
            'status' => 'queued',
            'payload' => [
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'prompt' => $data['prompt'],
                'request_id' => CorrelationId::current(),
            ],
        ]);

        GenerateFeaturePlanJob::dispatch($aiJob->id);

        return response()->json(['job_id' => $aiJob->id, 'status' => $aiJob->status], 202);
    }

    public function show(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorize('view', $project);
        abort_unless($featureRequest->project_id === $project->id, 404);

        return $featureRequest->load('changeSet.files');
    }

    public function approvePlan(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorize('act', $project);
        abort_unless($featureRequest->project_id === $project->id, 404);

        $data = $request->validate([
            'approved_paths' => ['required', 'array'],
            'approved_paths.*' => ['string'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $result = $this->agent->approvePlan($changeSet, $data['approved_paths']);

        $this->audit->record($request->user(), 'feature.plan_approved', [
            'feature_request_id' => $featureRequest->id,
            'approved_paths' => $data['approved_paths'],
        ], project: $project);

        return response()->json($result);
    }

    /**
     * Returns 202 + a job id immediately — generateContent() makes one AI
     * call per approved file, sequentially, making this the longest-running
     * of the AI endpoints. The frontend polls GET /ai-jobs/{id}; once it
     * succeeds, the job's result carries the change_set_id to refetch.
     */
    public function generate(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorize('act', $project);
        abort_unless($featureRequest->project_id === $project->id, 404);

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

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'type' => 'feature_content',
            'status' => 'queued',
            'payload' => [
                'change_set_id' => $changeSet->id,
                'current_content_by_path' => $currentContentByPath,
                'request_id' => CorrelationId::current(),
            ],
        ]);

        GenerateFeatureContentJob::dispatch($aiJob->id);

        return response()->json(['job_id' => $aiJob->id, 'status' => $aiJob->status], 202);
    }

    public function approveDiff(Request $request, Project $project, FeatureRequest $featureRequest)
    {
        $this->authorize('act', $project);
        abort_unless($featureRequest->project_id === $project->id, 404);

        $data = $request->validate([
            'approved_paths' => ['required', 'array'],
            'approved_paths.*' => ['string'],
        ]);

        $changeSet = $featureRequest->changeSet;
        abort_unless($changeSet, 404);

        $this->changeSets->approveDiff($changeSet, $data['approved_paths']);

        $this->audit->record($request->user(), 'feature.diff_approved', [
            'feature_request_id' => $featureRequest->id,
            'approved_paths' => $data['approved_paths'],
        ], project: $project);

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
        $this->authorize('act', $project);
        abort_unless($featureRequest->project_id === $project->id, 404);

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

        $this->audit->record($request->user(), 'feature.applied', [
            'feature_request_id' => $featureRequest->id,
            'applied_paths' => $data['applied_paths'],
        ], project: $project);

        return response()->json([
            'feature_request' => $featureRequest->fresh(),
            'checkpoint' => $after,
        ]);
    }
}
