<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use Illuminate\Http\Request;

class AiJobController extends Controller
{
    public function show(Request $request, AiJob $aiJob)
    {
        $this->authorize('view', $aiJob->project);

        return response()->json($aiJob->only(['id', 'type', 'status', 'result', 'error']));
    }

    /**
     * Only takes effect while the job is still 'queued' — once a worker has
     * picked it up there's no way to interrupt an in-flight AI provider
     * call, so cancelling a running job just prevents a future retry after
     * a failure, it can't stop the current attempt.
     */
    public function cancel(Request $request, AiJob $aiJob)
    {
        $this->authorize('act', $aiJob->project);

        if ($aiJob->status === 'queued') {
            $aiJob->update(['cancelled_at' => now(), 'status' => 'cancelled']);
        }

        return response()->json($aiJob->only(['id', 'type', 'status', 'result', 'error']));
    }
}
