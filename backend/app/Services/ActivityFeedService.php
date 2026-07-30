<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Collection;

class ActivityFeedService
{
    /**
     * A read-only aggregation over already-real, already-timestamped,
     * already-attributed tables — no new event/write path. Each entry is
     * normalized to {type, summary, user, created_at} so the frontend can
     * render one chronological list without knowing about six schemas.
     */
    public function feed(Project $project, int $limit = 30): array
    {
        $entries = collect()
            ->concat($this->featureRequestEntries($project))
            ->concat($this->checkpointEntries($project))
            ->concat($this->testRunEntries($project))
            ->concat($this->deploymentEntries($project))
            ->concat($this->commentEntries($project))
            ->concat($this->taskEntries($project));

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['created_at'])
            ->values()
            ->take($limit)
            ->all();
    }

    private function featureRequestEntries(Project $project): Collection
    {
        return $project->featureRequests()->with('user:id,name')->get()->map(fn ($fr) => [
            'id' => "feature_request:{$fr->id}",
            'type' => 'feature_request',
            'summary' => "requested: \"{$this->truncate($fr->prompt)}\"",
            'user' => $fr->user?->name,
            'created_at' => $fr->created_at,
        ]);
    }

    private function checkpointEntries(Project $project): Collection
    {
        return $project->checkpoints()->with(['featureRequest.user:id,name', 'deployment.user:id,name'])->get()->map(fn ($cp) => [
            'id' => "checkpoint:{$cp->id}",
            'type' => 'checkpoint',
            'summary' => "checkpoint: {$cp->message}",
            'user' => $cp->featureRequest?->user?->name ?? $cp->deployment?->user?->name,
            'created_at' => $cp->created_at,
        ]);
    }

    private function testRunEntries(Project $project): Collection
    {
        return $project->testRuns()->with('user:id,name')->get()->map(fn ($run) => [
            'id' => "test_run:{$run->id}",
            'type' => 'test_run',
            'summary' => "ran {$run->framework} tests: {$run->passed_count}/{$run->total_count} passed",
            'user' => $run->user?->name,
            'created_at' => $run->created_at,
        ]);
    }

    private function deploymentEntries(Project $project): Collection
    {
        return $project->deployments()->with('user:id,name')->get()->map(fn ($d) => [
            'id' => "deployment:{$d->id}",
            'type' => 'deployment',
            'summary' => "deployed to {$d->platform}: {$d->status}",
            'user' => $d->user?->name,
            'created_at' => $d->updated_at,
        ]);
    }

    private function commentEntries(Project $project): Collection
    {
        return $project->comments()->with('user:id,name')->get()->map(fn ($c) => [
            'id' => "comment:{$c->id}",
            'type' => 'comment',
            'summary' => "commented: \"{$this->truncate($c->body)}\"",
            'user' => $c->user?->name,
            'created_at' => $c->created_at,
        ]);
    }

    private function taskEntries(Project $project): Collection
    {
        return $project->tasks()->with('assignee:id,name')->get()->map(fn ($task) => [
            'id' => "task:{$task->id}",
            'type' => 'task',
            'summary' => "task \"{$task->title}\" is {$task->status}".($task->assignee ? " (assigned to {$task->assignee->name})" : ''),
            'user' => $task->assignee?->name,
            // No dedicated status-change log exists — updated_at is the
            // closest real signal to "when this task last changed", used as
            // written rather than fabricating a new audit table for it.
            'created_at' => $task->updated_at,
        ]);
    }

    private function truncate(string $text, int $length = 80): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
