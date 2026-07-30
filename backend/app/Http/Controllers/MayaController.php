<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CorrelationId;
use App\Jobs\ProcessMayaMessageJob;
use App\Models\AiJob;
use App\Models\Project;
use Illuminate\Http\Request;

class MayaController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        // orderByDesc('id') rather than latest() — created_at has only
        // second-level granularity and user+assistant rows in the same turn
        // are created within the same second, so created_at alone ties and
        // doesn't guarantee chronological order.
        // featureRequest.changeSet.files is eager-loaded so a page reload
        // still has everything a FeatureCard needs to render the plan/diff,
        // with no second round-trip.
        return $project->mayaMessages()
            ->with('featureRequest.changeSet.files')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Returns 202 + a job id immediately — handleMessage() makes at least
     * one AI call (classify) and often two (classify + reply, or classify +
     * a full feature-planning call), which used to block this request for
     * the whole round trip. The frontend renders the user's own message
     * optimistically on send and polls GET /ai-jobs/{id} for the assistant's
     * reply rather than waiting on this response.
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'active_file' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'type' => 'maya_message',
            'status' => 'queued',
            'payload' => [
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'message' => $data['message'],
                'active_file' => $data['active_file'] ?? null,
                'request_id' => CorrelationId::current(),
            ],
        ]);

        ProcessMayaMessageJob::dispatch($aiJob->id);

        return response()->json(['job_id' => $aiJob->id, 'status' => $aiJob->status], 202);
    }
}
