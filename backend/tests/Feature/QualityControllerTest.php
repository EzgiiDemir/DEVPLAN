<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Quality Test']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_detect_returns_only_applicable_commands(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/quality/detect", [
            'has_package_json' => true,
            'has_eslint_config' => false,
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('npm_audit', $response->json('commands'));
        $this->assertArrayNotHasKey('eslint', $response->json('commands'));
    }

    public function test_scan_persists_a_snapshot_and_merges_latest_coverage(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        // A prior test run's coverage should show up in the quality snapshot
        // without needing to be duplicated into it.
        $project->testRuns()->create([
            'user_id' => $user->id,
            'framework' => 'jest',
            'status' => 'passed',
            'passed_count' => 5,
            'failed_count' => 0,
            'total_count' => 5,
            'coverage_percent' => 82.5,
        ]);

        $npmAuditJson = json_encode([
            'vulnerabilities' => ['lodash' => ['severity' => 'high', 'via' => [['title' => 'Prototype Pollution']]]],
            'metadata' => ['vulnerabilities' => ['critical' => 0, 'high' => 1, 'moderate' => 0, 'low' => 0]],
        ]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/quality/scan", [
            'npm_audit_json' => $npmAuditJson,
        ]);

        $response->assertOk()
            ->assertJsonPath('security.high', 1)
            ->assertJsonPath('coverage_percent', 82.5)
            ->assertJsonPath('performance', null);

        $fresh = $this->actingAs($user)->getJson("/api/projects/{$project->id}/quality");
        $fresh->assertOk()->assertJsonPath('security.high', 1);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $project->refresh();
        $this->assertNotNull($project->quality_scanned_at);
    }

    public function test_quality_endpoints_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($intruder)->getJson("/api/projects/{$project->id}/quality")->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/projects/{$project->id}/quality/scan", [])->assertForbidden();
    }
}
