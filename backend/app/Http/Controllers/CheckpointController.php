<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

class CheckpointController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $project->checkpoints()->with('deployment')->limit(50)->get();
    }
}
