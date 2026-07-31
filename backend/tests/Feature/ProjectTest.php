<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_MODULE_ORDER = [
        'idea', 'research', 'requirements', 'mvp_scope', 'tech_stack', 'design',
        'api_design', 'folder_structure', 'environment', 'task_plan',
        'ai_resources', 'prompt_engineering',
    ];

    public function test_creating_a_project_seeds_all_twelve_modules_in_order(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', [
            'title' => 'CRM',
            'description' => 'A simple CRM',
        ]);

        $response->assertCreated();
        $modules = $response->json('modules');

        $this->assertCount(12, $modules);
        $this->assertSame(self::EXPECTED_MODULE_ORDER, array_column($modules, 'module_type'));

        foreach ($modules as $module) {
            $this->assertSame('not_started', $module['status']);
        }
    }

    public function test_user_only_sees_their_own_projects(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/v1/projects', ['title' => 'Owner Project']);
        $this->actingAs($other)->postJson('/api/v1/projects', ['title' => 'Other Project']);

        $response = $this->actingAs($owner)->getJson('/api/v1/projects');

        $response->assertOk();
        $titles = array_column($response->json(), 'title');
        $this->assertSame(['Owner Project'], $titles);
    }

    public function test_user_cannot_view_another_users_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $created = $this->actingAs($owner)->postJson('/api/v1/projects', ['title' => 'Private Project']);
        $projectId = $created->json('id');

        $response = $this->actingAs($intruder)->getJson("/api/v1/projects/{$projectId}");

        $response->assertForbidden();
    }

    public function test_project_requires_a_title(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', []);

        $response->assertStatus(422);
    }

    public function test_deleting_a_project_removes_it(): void
    {
        $user = User::factory()->create();
        $created = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Temp Project']);

        $this->actingAs($user)->deleteJson("/api/v1/projects/{$created->json('id')}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $created->json('id')]);
    }

    public function test_workspace_state_persists_and_round_trips(): void
    {
        $user = User::factory()->create();
        $created = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'IDE Test']);
        $projectId = $created->json('id');

        $state = [
            'openTabs' => ['src/index.js', 'src/App.jsx'],
            'activeTab' => 'src/App.jsx',
            'cursorPositions' => ['src/App.jsx' => ['line' => 12, 'column' => 4]],
            'lastActiveFile' => 'src/App.jsx',
        ];

        $response = $this->actingAs($user)->patchJson("/api/v1/projects/{$projectId}/workspace-state", [
            'workspace_state' => $state,
        ]);

        $response->assertOk()->assertJsonPath('workspace_state.activeTab', 'src/App.jsx');

        $refetched = $this->actingAs($user)->getJson("/api/v1/projects/{$projectId}");
        $this->assertSame($state['openTabs'], $refetched->json('workspace_state.openTabs'));
        // Accessed without dot-notation — the key itself ("src/App.jsx")
        // contains a literal dot, which Laravel's json() path helper would
        // otherwise misinterpret as nesting.
        $this->assertSame(12, $refetched->json('workspace_state.cursorPositions')['src/App.jsx']['line']);
    }

    public function test_workspace_state_requires_ownership(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/api/v1/projects', ['title' => 'Private']);

        $this->actingAs($intruder)
            ->patchJson("/api/v1/projects/{$created->json('id')}/workspace-state", ['workspace_state' => ['activeTab' => 'x']])
            ->assertForbidden();
    }

    /**
     * Covers the plan-gating added alongside the Stripe billing system: a
     * Free-plan account is capped at one project, matching the plan copy
     * shown in Settings ("1 project" for Free vs. "Unlimited" for Pro/Team).
     */
    public function test_a_free_plan_user_cannot_create_a_second_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'First'])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Second']);

        $response->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_a_pro_plan_user_can_create_a_second_project(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['plan' => 'pro']);
        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'First'])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Second']);

        $response->assertCreated();
        $this->assertDatabaseCount('projects', 2);
    }

    /**
     * Covers the demo-project onboarding feature: a one-click project that
     * comes pre-filled with real module content and indexed files, rather
     * than 12 blank modules — and still respects the same plan limit as a
     * normal project (it isn't a free extra project).
     */
    public function test_demo_project_is_created_with_real_module_content_and_indexed_files(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/projects/demo');

        $response->assertCreated();
        $projectId = $response->json('id');

        $modules = collect($response->json('modules'));
        $ideaModule = $modules->firstWhere('module_type', 'idea');
        $this->assertSame('completed', $ideaModule['status']);
        $this->assertNotEmpty($ideaModule['items']);

        $this->assertDatabaseHas('project_files', ['project_id' => $projectId, 'path' => 'backend/app/Models/Task.php']);
        $this->assertGreaterThanOrEqual(3, \App\Models\ProjectFile::where('project_id', $projectId)->count());
    }

    public function test_demo_project_still_counts_against_the_free_plan_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/projects/demo')->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/v1/projects/demo');

        $response->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
    }
}
