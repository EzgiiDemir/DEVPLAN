<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TeamMember;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        return $project->tasks()->with('assignee:id,name')->orderBy('id')->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('act', $project);

        $data = $this->validated($request, $project);

        // create() only reflects attributes actually passed in — DB column
        // defaults (status/priority) wouldn't otherwise appear on the
        // in-memory model returned to the caller without a second query.
        $task = $project->tasks()->create([
            'status' => 'todo',
            'priority' => 'medium',
            ...$data,
        ]);

        return response()->json($task->load('assignee:id,name'), 201);
    }

    public function update(Request $request, Project $project, Task $task)
    {
        $this->authorize('act', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $this->validated($request, $project, sometimes: true);

        $task->update($data);

        return $task->load('assignee:id,name');
    }

    public function destroy(Request $request, Project $project, Task $task)
    {
        $this->authorize('act', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->delete();

        return response()->noContent();
    }

    private function validated(Request $request, Project $project, bool $sometimes = false): array
    {
        $rule = fn (array $rules) => $sometimes ? ['sometimes', ...$rules] : $rules;

        $data = $request->validate([
            'title' => $rule(['required', 'string', 'max:255']),
            'status' => ['sometimes', 'string', 'in:todo,doing,done'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'module_item_id' => ['sometimes', 'nullable', 'integer', 'exists:module_items,id'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('assigned_to_user_id', $data) && $data['assigned_to_user_id'] !== null) {
            $isTeamMember = TeamMember::where('team_id', $project->team_id)
                ->where('user_id', $data['assigned_to_user_id'])
                ->exists();

            abort_unless($isTeamMember, 422, 'A task can only be assigned to a member of the project\'s team.');
        }

        return $data;
    }
}
