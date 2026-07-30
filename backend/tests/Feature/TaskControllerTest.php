<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $developer->id, 'role' => 'developer']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Task Project']);

        return compact('owner', 'developer', 'viewer', 'team', 'project');
    }

    public function test_a_developer_can_create_update_and_assign_a_task_to_a_real_team_member(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->scenario();

        $create = $this->actingAs($developer)->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Build the thing']);
        $create->assertCreated()->assertJsonPath('status', 'todo');
        $taskId = $create->json('id');

        $update = $this->actingAs($developer)->patchJson("/api/projects/{$project->id}/tasks/{$taskId}", [
            'status' => 'doing',
            'assigned_to_user_id' => $developer->id,
        ]);
        $update->assertOk()->assertJsonPath('status', 'doing')->assertJsonPath('assignee.id', $developer->id);
    }

    public function test_a_task_cannot_be_assigned_to_someone_outside_the_project_team(): void
    {
        ['developer' => $developer, 'project' => $project] = $this->scenario();
        $outsider = User::factory()->create();

        $this->actingAs($developer)->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Build the thing',
            'assigned_to_user_id' => $outsider->id,
        ])->assertStatus(422);
    }

    public function test_a_viewer_can_list_tasks_but_not_create_them(): void
    {
        ['developer' => $developer, 'viewer' => $viewer, 'project' => $project] = $this->scenario();

        $this->actingAs($developer)->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Task 1']);

        $this->actingAs($viewer)->getJson("/api/projects/{$project->id}/tasks")->assertOk()->assertJsonCount(1);
        $this->actingAs($viewer)->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Task 2'])->assertForbidden();
    }
}
