<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DevEngine\ContextEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleItemTest extends TestCase
{
    use RefreshDatabase;

    private function firstModuleId($user): int
    {
        $project = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Test Project']);

        return $project->json('modules.0.id');
    }

    private function techStackModuleId($user, Project $project): int
    {
        $modules = $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}")->json('modules');

        return collect($modules)->firstWhere('module_type', 'tech_stack')['id'];
    }

    public function test_can_create_and_list_items_of_any_item_type(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $this->actingAs($user)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['problem' => ['Test problem']],
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/v1/modules/{$moduleId}/items");

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame(['Test problem'], $response->json('0.content.problem'));
    }

    public function test_can_update_an_item(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $created = $this->actingAs($user)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['problem' => ['Old']],
        ]);

        $this->actingAs($user)->putJson("/api/v1/items/{$created->json('id')}", [
            'content' => ['problem' => ['New']],
        ])->assertOk()->assertJsonPath('content.problem.0', 'New');
    }

    public function test_can_delete_an_item(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $created = $this->actingAs($user)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/items/{$created->json('id')}")->assertNoContent();
        $this->assertDatabaseMissing('module_items', ['id' => $created->json('id')]);
    }

    public function test_cannot_read_items_belonging_to_another_users_module(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $this->actingAs($owner)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($intruder)->getJson("/api/v1/modules/{$moduleId}/items")->assertForbidden();
    }

    public function test_cannot_update_an_item_belonging_to_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $created = $this->actingAs($owner)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($intruder)
            ->putJson("/api/v1/items/{$created->json('id')}", ['content' => ['hacked' => true]])
            ->assertForbidden();
    }

    /**
     * Covers the Redis-backed cache invalidation added to this controller:
     * ContextEngineService::techStack() caches its result, so writing a new
     * tech_stack item must clear that cache immediately — otherwise the
     * very next AI generation would ground itself in a stale/empty stack.
     */
    public function test_creating_a_tech_stack_item_invalidates_the_cached_tech_stack(): void
    {
        $user = User::factory()->create();
        $project = Project::findOrFail($this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Cache Test'])->json('id'));
        $moduleId = $this->techStackModuleId($user, $project);

        $before = app(ContextEngineService::class)->techStack($project);
        $this->assertSame('', $before['backend']);

        $this->actingAs($user)->postJson("/api/v1/modules/{$moduleId}/items", [
            'item_type' => 'tech_stack',
            'content' => ['backend' => ['selected' => 'Laravel']],
        ])->assertCreated();

        $after = app(ContextEngineService::class)->techStack($project);
        $this->assertSame('Laravel', $after['backend']);
    }

    public function test_module_status_can_be_updated_and_is_scoped_to_owner(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $this->actingAs($owner)
            ->putJson("/api/v1/modules/{$moduleId}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->actingAs($intruder)
            ->putJson("/api/v1/modules/{$moduleId}", ['status' => 'completed'])
            ->assertForbidden();
    }
}
