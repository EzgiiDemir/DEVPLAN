<?php

namespace App\Services;

use App\Models\Project;
use ZipArchive;

/**
 * Builds a downloadable ZIP snapshot of everything DevPlan itself holds for
 * a project — the 12-module plan, tasks, comments, feature requests, Maya
 * history, and deployment history. Deliberately does not attempt to bundle
 * the actual local codebase: that already lives in the user's own git repo
 * on disk (Companion works against it directly), so re-zipping it here would
 * just be a stale, disconnected copy of something git already versions.
 */
class ProjectExportService
{
    public function build(Project $project): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'devplan-export-').'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->addProjectToZip($zip, $project);

        $zip->close();

        return $zipPath;
    }

    /**
     * Writes one project's export files into an already-open archive under
     * an optional path prefix — shared by build() (a standalone per-project
     * zip) and AccountExportService (many projects folded into one
     * account-wide GDPR export), so the per-project file layout is defined
     * exactly once.
     */
    public function addProjectToZip(ZipArchive $zip, Project $project, string $prefix = ''): void
    {
        $project->load([
            'modules.items',
            'tasks.assignee:id,name',
            'comments.user:id,name',
            'featureRequests.user:id,name',
            'mayaMessages.user:id,name',
            'deployments',
        ]);

        $zip->addFromString("{$prefix}project.json", $this->json([
            'title' => $project->title,
            'description' => $project->description,
            'created_at' => $project->created_at?->toIso8601String(),
            'exported_at' => now()->toIso8601String(),
        ]));

        foreach ($project->modules as $module) {
            $zip->addFromString(
                "{$prefix}modules/{$module->module_type}.json",
                $this->json($module->items->map(fn ($item) => [
                    'item_type' => $item->item_type,
                    'content' => $item->content,
                    'is_ai_generated' => $item->is_ai_generated,
                    'is_user_edited' => $item->is_user_edited,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])),
            );
        }

        $zip->addFromString("{$prefix}tasks.json", $this->json($project->tasks->map(fn ($task) => [
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'assignee' => $task->assignee?->name,
            'created_at' => $task->created_at?->toIso8601String(),
        ])));

        $zip->addFromString("{$prefix}comments.json", $this->json($project->comments->map(fn ($comment) => [
            'author' => $comment->user?->name,
            'body' => $comment->body,
            'commentable_type' => $comment->commentable_type,
            'created_at' => $comment->created_at?->toIso8601String(),
        ])));

        $zip->addFromString("{$prefix}feature_requests.json", $this->json($project->featureRequests->map(fn ($feature) => [
            'requested_by' => $feature->user?->name,
            'prompt' => $feature->prompt,
            'status' => $feature->status,
            'created_at' => $feature->created_at?->toIso8601String(),
        ])));

        $zip->addFromString("{$prefix}maya_conversations.json", $this->json($project->mayaMessages->map(fn ($message) => [
            'author' => $message->user?->name,
            'role' => $message->role,
            'intent' => $message->intent,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ])));

        $zip->addFromString("{$prefix}deployments.json", $this->json($project->deployments->map(fn ($deployment) => [
            'platform' => $deployment->platform,
            'status' => $deployment->status,
            'live_url' => $deployment->live_url,
            'created_at' => $deployment->created_at?->toIso8601String(),
        ])));

        $zip->addFromString("{$prefix}README.txt", $this->readme($project));
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function readme(Project $project): string
    {
        return <<<TEXT
        DevPlan export — {$project->title}
        Generated: {$project->created_at?->toIso8601String()}

        This archive contains DevPlan's own records for this project: the
        12-module plan, tasks, comments, feature requests, Maya conversation
        history, and deployment history — each as a plain JSON file.

        It does not contain your project's source code. That lives in your
        own local git repository; DevPlan Companion works against it directly
        and git itself is the right tool for backing it up.
        TEXT;
    }
}
