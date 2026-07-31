<?php

namespace App\Services;

use App\Models\Project;

/**
 * Fills a freshly created (still entirely blank) project with realistic
 * content across a handful of its 12 modules, plus a few indexed files —
 * so a brand-new user can explore Studio, Maya, and the Project Brain
 * immediately instead of the empty state every module starts in. Every
 * module/item this touches already exists (ProjectController::store()
 * creates all 12 empty modules for every new project); this only fills
 * them in and marks the ones it seeded as completed.
 */
class DemoProjectSeeder
{
    public function seed(Project $project): void
    {
        $modules = $project->modules()->get()->keyBy('module_type');

        $this->fillModule($modules['idea'] ?? null, 'lean_canvas', [
            'problem' => ['Freelancers lose track of which client tasks are actually billable.'],
            'solution' => ['A lightweight task tracker that tags every task with a client and billable status.'],
            'customer' => ['Freelance developers and designers'],
            'revenue' => ['$9/month per user'],
            'cost' => ['Hosting', 'AI usage'],
            'channels' => ['Twitter/X', 'Indie Hackers'],
        ]);

        $this->fillModule($modules['mvp_scope'] ?? null, 'mvp_item', null, [
            ['column' => 'must', 'feature' => 'Create and list tasks per client'],
            ['column' => 'must', 'feature' => 'Mark a task billable/non-billable'],
            ['column' => 'should', 'feature' => 'Weekly billable-hours summary'],
            ['column' => 'could', 'feature' => 'Export invoice as PDF'],
        ]);

        $this->fillModule($modules['tech_stack'] ?? null, 'tech_stack', [
            'frontend' => ['selected' => 'Next.js'],
            'backend' => ['selected' => 'Laravel'],
            'database' => ['selected' => 'PostgreSQL'],
        ]);

        $this->fillModule($modules['folder_structure'] ?? null, 'scaffold_tree', [
            'stack' => ['frontend' => 'Next.js', 'backend' => 'Laravel', 'database' => 'PostgreSQL'],
            'tree' => [
                'name' => 'task-tracker', 'type' => 'folder', 'children' => [
                    ['name' => 'backend', 'type' => 'folder', 'children' => [
                        ['name' => 'app', 'type' => 'folder', 'children' => [
                            ['name' => 'Http', 'type' => 'folder', 'children' => [
                                ['name' => 'Controllers', 'type' => 'folder', 'children' => [
                                    ['name' => 'TaskController.php', 'type' => 'file'],
                                    ['name' => 'ClientController.php', 'type' => 'file'],
                                ]],
                            ]],
                            ['name' => 'Models', 'type' => 'folder', 'children' => [
                                ['name' => 'Task.php', 'type' => 'file'],
                                ['name' => 'Client.php', 'type' => 'file'],
                            ]],
                        ]],
                    ]],
                    ['name' => 'frontend', 'type' => 'folder', 'children' => [
                        ['name' => 'src', 'type' => 'folder', 'children' => [
                            ['name' => 'components', 'type' => 'folder', 'children' => [
                                ['name' => 'TaskList.jsx', 'type' => 'file'],
                                ['name' => 'ClientBadge.jsx', 'type' => 'file'],
                            ]],
                        ]],
                    ]],
                ],
            ],
        ]);

        $this->seedIndexedFiles($project);
    }

    private function fillModule($module, string $itemType, ?array $singleContent, ?array $multipleContents = null): void
    {
        if (! $module) {
            return;
        }

        if ($multipleContents !== null) {
            foreach ($multipleContents as $content) {
                $module->items()->create(['item_type' => $itemType, 'content' => $content, 'is_ai_generated' => true]);
            }
        } else {
            $module->items()->create(['item_type' => $itemType, 'content' => $singleContent, 'is_ai_generated' => true]);
        }

        $module->update(['status' => 'completed']);
    }

    /**
     * A handful of already-"indexed" files (summary/symbols filled in as if
     * a real scan had already run) so Project Brain and Studio's file
     * explorer have real, explorable content — matching what
     * CodebaseIndexer::indexFiles() would have produced from a real scan.
     */
    private function seedIndexedFiles(Project $project): void
    {
        $project->files()->create([
            'path' => 'backend/app/Models/Task.php',
            'language' => 'php',
            'content_hash' => 'demo-task-model',
            'summary' => 'Eloquent model for a task, belongs to a Client, has a billable flag.',
            'symbols' => ['Task'],
        ]);

        $project->files()->create([
            'path' => 'backend/app/Models/Client.php',
            'language' => 'php',
            'content_hash' => 'demo-client-model',
            'summary' => 'Eloquent model for a client, has many Tasks.',
            'symbols' => ['Client'],
        ]);

        $project->files()->create([
            'path' => 'frontend/src/components/TaskList.jsx',
            'language' => 'javascript',
            'content_hash' => 'demo-tasklist-component',
            'summary' => 'Renders a client\'s tasks with a billable toggle.',
            'symbols' => ['TaskList'],
        ]);
    }
}
