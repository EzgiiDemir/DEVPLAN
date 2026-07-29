<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DevEngine\MayaChatService;
use Illuminate\Http\Request;

class MayaController extends Controller
{
    public function __construct(private MayaChatService $maya) {}

    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

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

    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'active_file' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $result = $this->maya->handleMessage($project, $request->user(), $data['message'], $data['active_file'] ?? null);
        collect($result['messages'])->each->load('featureRequest.changeSet.files');

        return response()->json($result, 201);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
