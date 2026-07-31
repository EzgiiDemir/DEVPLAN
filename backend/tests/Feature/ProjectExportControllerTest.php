<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Export Test Project']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_a_project_member_can_download_the_export_zip(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->get("/api/v1/projects/{$project->id}/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('devplan-export.zip', $response->headers->get('content-disposition'));
    }

    public function test_a_non_team_member_cannot_download_the_export(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($outsider)->get("/api/v1/projects/{$project->id}/export")->assertForbidden();
    }

    public function test_a_viewer_can_still_download_the_export(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Viewer Export Project']);

        $this->actingAs($viewer)->get("/api/v1/projects/{$project->id}/export")->assertOk();
    }
}
