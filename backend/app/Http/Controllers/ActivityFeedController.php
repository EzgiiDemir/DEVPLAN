<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ActivityFeedService;
use Illuminate\Http\Request;

class ActivityFeedController extends Controller
{
    public function __construct(private ActivityFeedService $feed) {}

    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $this->feed->feed($project);
    }
}
