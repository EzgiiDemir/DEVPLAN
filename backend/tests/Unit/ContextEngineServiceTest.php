<?php

namespace Tests\Unit;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\DevEngine\ContextEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_activity_attributes_each_entry_to_the_real_requesting_user(): void
    {
        $owner = User::factory()->create(['name' => 'Ezgi Demir']);
        $teammate = User::factory()->create(['name' => 'Ada Lovelace']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'role' => 'developer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Attribution Project']);

        $project->featureRequests()->create(['user_id' => $owner->id, 'prompt' => 'add a login page', 'status' => 'applied']);
        $project->featureRequests()->create(['user_id' => $teammate->id, 'prompt' => 'add a logout button', 'status' => 'applied']);

        $summary = app(ContextEngineService::class)->recentActivity($project);

        $this->assertStringContainsString('Ezgi Demir asked: add a login page', $summary);
        $this->assertStringContainsString('Ada Lovelace asked: add a logout button', $summary);
    }

    public function test_sprint_status_summarizes_real_task_counts_and_assignees(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create(['name' => 'Ada Lovelace']);
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $assignee->id, 'role' => 'developer']);

        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Sprint Project']);

        $project->tasks()->create(['title' => 'Build login form', 'status' => 'doing', 'assigned_to_user_id' => $assignee->id]);
        $project->tasks()->create(['title' => 'Write docs', 'status' => 'todo']);
        $project->tasks()->create(['title' => 'Ship it', 'status' => 'done']);

        $summary = app(ContextEngineService::class)->sprintStatus($project);

        $this->assertStringContainsString('1 todo, 1 in progress, 1 done (3 total)', $summary);
        $this->assertStringContainsString('Build login form (assigned to Ada Lovelace)', $summary);
    }

    public function test_coding_standards_prefers_the_recorded_scaffold_tree_when_present(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['title' => 'Scaffold Project']);

        $module = $project->modules()->create(['module_type' => 'folder_structure', 'order_index' => 1]);
        $module->items()->create([
            'item_type' => 'scaffold_tree',
            'content' => [
                'tree' => [
                    'name' => 'root', 'type' => 'folder', 'children' => [
                        ['name' => 'backend', 'type' => 'folder', 'children' => [
                            ['name' => 'app', 'type' => 'folder', 'children' => [
                                ['name' => 'ProductController.php', 'type' => 'file'],
                            ]],
                        ]],
                    ],
                ],
            ],
        ]);

        // A real indexed file exists too — the scaffold tree must still win.
        $project->files()->create(['path' => 'src/Widget.js', 'language' => 'javascript', 'content_hash' => 'x', 'symbols' => ['Widget']]);

        $standards = app(ContextEngineService::class)->codingStandards($project);

        $this->assertStringContainsString('backend/app/ProductController.php', $standards);
        $this->assertStringNotContainsString('Widget.js', $standards);
    }

    public function test_coding_standards_falls_back_to_indexed_files_when_no_scaffold_tree_exists(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['title' => 'No Scaffold Project']);

        $project->files()->create([
            'path' => 'app/Models/Wishlist.php',
            'language' => 'php',
            'content_hash' => 'x',
            'symbols' => ['Wishlist'],
        ]);

        $standards = app(ContextEngineService::class)->codingStandards($project);

        $this->assertStringContainsString('app/Models/Wishlist.php: Wishlist', $standards);
    }

    public function test_coding_standards_is_null_when_neither_source_has_anything(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['title' => 'Empty Project']);

        $this->assertNull(app(ContextEngineService::class)->codingStandards($project));
    }
}
