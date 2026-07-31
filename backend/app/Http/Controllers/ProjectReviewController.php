<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CorrelationId;
use App\Jobs\ValidateProjectJob;
use App\Models\AiJob;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * The Project Review screen shown between the 12 planning modules and
 * Studio. The summary itself (module completion, tech stack, sprint count)
 * needs no new endpoint — ProjectController::show() already returns
 * modules.items, which is everything the frontend needs to render it. This
 * controller is only the one thing that genuinely needs a server round
 * trip: the AI contradiction check.
 */
class ProjectReviewController extends Controller
{
    /**
     * Returns 202 + a job id immediately, same convention as every other AI
     * action in this app — the frontend polls GET /ai-jobs/{id}.
     */
    public function validate(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'type' => 'project_validation',
            'status' => 'queued',
            'payload' => [
                'project_id' => $project->id,
                'request_id' => CorrelationId::current(),
            ],
        ]);

        ValidateProjectJob::dispatch($aiJob->id);

        return response()->json(['job_id' => $aiJob->id, 'status' => $aiJob->status], 202);
    }
}
