<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Services\AiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user): Project
    {
        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Review Test Project']);

        return Project::findOrFail($response->json('id'));
    }

    public function test_validate_returns_real_contradictions_found_by_the_ai(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $module = Module::where('project_id', $project->id)->where('module_type', 'tech_stack')->firstOrFail();
        $module->items()->create([
            'item_type' => 'tech_stack',
            'content' => ['backend' => ['selected' => 'Django'], 'database' => ['selected' => 'MongoDB']],
        ]);

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode([
                'issues' => [
                    ['severity' => 'high', 'message' => 'Django is chosen but MongoDB has no first-class Django ORM support.'],
                ],
            ]));
        });

        $store = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/review/validate");
        $store->assertStatus(202);

        $job = $this->actingAs($user)->getJson("/api/v1/ai-jobs/{$store->json('job_id')}");
        $job->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertCount(1, $job->json('result.issues'));
        $this->assertSame('high', $job->json('result.issues.0.severity'));
    }

    public function test_validate_returns_no_issues_when_the_ai_finds_none(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(json_encode(['issues' => []]));
        });

        $store = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/review/validate");
        $job = $this->actingAs($user)->getJson("/api/v1/ai-jobs/{$store->json('job_id')}");

        $job->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertCount(0, $job->json('result.issues'));
    }

    public function test_a_non_team_member_cannot_trigger_validation(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->projectFor($owner);

        $this->actingAs($outsider)->postJson("/api/v1/projects/{$project->id}/review/validate")->assertForbidden();
    }

    public function test_a_malformed_ai_response_yields_an_empty_issue_list_rather_than_failing(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->mock(AiTextGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('not valid json at all');
        });

        $store = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/review/validate");
        $job = $this->actingAs($user)->getJson("/api/v1/ai-jobs/{$store->json('job_id')}");

        $job->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertCount(0, $job->json('result.issues'));
    }
}
