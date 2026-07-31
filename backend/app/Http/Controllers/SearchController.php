<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\FeatureRequest;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * A single cross-project lookup so a query for e.g. "stripe" can surface a
 * project, a task, a comment thread, and the module content that mentioned
 * it, instead of the user having to know which of the 12 modules to check.
 * Deliberately LIKE-based rather than a dedicated search engine — result
 * volumes here are one user's own projects, not a public corpus.
 */
class SearchController extends Controller
{
    private const RESULTS_PER_TYPE = 10;
    private const EXCERPT_LENGTH = 160;

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);
        $term = $data['q'];
        $like = "%{$term}%";

        $projectIds = Project::whereIn('team_id', $request->user()->teams()->pluck('teams.id'))->pluck('id');

        $projects = Project::whereIn('id', $projectIds)
            ->where(fn ($q) => $q->whereLike('title', $like)->orWhereLike('description', $like))
            ->latest()
            ->limit(self::RESULTS_PER_TYPE)
            ->get(['id', 'title', 'description'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->title,
                'excerpt' => $this->excerpt($project->description),
            ]);

        $tasks = Task::whereIn('project_id', $projectIds)
            ->whereLike('title', $like)
            ->with('project:id,title')
            ->latest()
            ->limit(self::RESULTS_PER_TYPE)
            ->get(['id', 'title', 'status', 'project_id'])
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'project_id' => $task->project_id,
                'project_title' => $task->project->title,
            ]);

        $features = FeatureRequest::whereIn('project_id', $projectIds)
            ->whereLike('prompt', $like)
            ->with('project:id,title')
            ->latest()
            ->limit(self::RESULTS_PER_TYPE)
            ->get(['id', 'prompt', 'status', 'project_id'])
            ->map(fn (FeatureRequest $feature) => [
                'id' => $feature->id,
                'excerpt' => $this->excerpt($feature->prompt),
                'status' => $feature->status,
                'project_id' => $feature->project_id,
                'project_title' => $feature->project->title,
            ]);

        $comments = Comment::whereIn('project_id', $projectIds)
            ->whereLike('body', $like)
            ->with('project:id,title')
            ->latest()
            ->limit(self::RESULTS_PER_TYPE)
            ->get(['id', 'body', 'project_id'])
            ->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'excerpt' => $this->excerpt($comment->body),
                'project_id' => $comment->project_id,
                'project_title' => $comment->project->title,
            ]);

        $moduleItems = ModuleItem::whereHas('module', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with(['module:id,project_id,module_type', 'module.project:id,title'])
            ->get()
            ->map(function (ModuleItem $item) {
                $text = implode(' ', $this->flattenStrings($item->content));

                return [$item, $text];
            })
            ->filter(fn (array $pair) => str_contains(mb_strtolower($pair[1]), mb_strtolower($term)))
            ->take(self::RESULTS_PER_TYPE)
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'module_type' => $pair[0]->module->module_type,
                'excerpt' => $this->excerpt($pair[1]),
                'project_id' => $pair[0]->module->project_id,
                'project_title' => $pair[0]->module->project->title,
            ])
            ->values();

        return response()->json([
            'projects' => $projects,
            'tasks' => $tasks,
            'features' => $features,
            'comments' => $comments,
            'module_items' => $moduleItems,
        ]);
    }

    private function excerpt(?string $text): string
    {
        $text = trim((string) $text);

        return mb_strlen($text) > self::EXCERPT_LENGTH
            ? mb_substr($text, 0, self::EXCERPT_LENGTH).'…'
            : $text;
    }

    /**
     * Module content is an arbitrary JSON structure that differs per module
     * type — flattening to just its string leaves gives a plain-text blob to
     * search/excerpt without matching on JSON keys or punctuation noise.
     */
    private function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $nested) {
                $result = array_merge($result, $this->flattenStrings($nested));
            }

            return $result;
        }

        return [];
    }
}
