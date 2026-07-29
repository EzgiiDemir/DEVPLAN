<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleItemTest extends TestCase
{
    use RefreshDatabase;

    private function firstModuleId($user): int
    {
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Test Project']);

        return $project->json('modules.0.id');
    }

    public function test_can_create_and_list_items_of_any_item_type(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $this->actingAs($user)->postJson("/api/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['problem' => ['Test problem']],
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/modules/{$moduleId}/items");

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame(['Test problem'], $response->json('0.content.problem'));
    }

    public function test_can_update_an_item(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $created = $this->actingAs($user)->postJson("/api/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['problem' => ['Old']],
        ]);

        $this->actingAs($user)->putJson("/api/items/{$created->json('id')}", [
            'content' => ['problem' => ['New']],
        ])->assertOk()->assertJsonPath('content.problem.0', 'New');
    }

    public function test_can_delete_an_item(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->firstModuleId($user);

        $created = $this->actingAs($user)->postJson("/api/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($user)->deleteJson("/api/items/{$created->json('id')}")->assertNoContent();
        $this->assertDatabaseMissing('module_items', ['id' => $created->json('id')]);
    }

    public function test_cannot_read_items_belonging_to_another_users_module(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $this->actingAs($owner)->postJson("/api/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($intruder)->getJson("/api/modules/{$moduleId}/items")->assertForbidden();
    }

    public function test_cannot_update_an_item_belonging_to_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $created = $this->actingAs($owner)->postJson("/api/modules/{$moduleId}/items", [
            'item_type' => 'lean_canvas',
            'content' => ['note' => 'test'],
        ]);

        $this->actingAs($intruder)
            ->putJson("/api/items/{$created->json('id')}", ['content' => ['hacked' => true]])
            ->assertForbidden();
    }

    public function test_module_status_can_be_updated_and_is_scoped_to_owner(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $moduleId = $this->firstModuleId($owner);

        $this->actingAs($owner)
            ->putJson("/api/modules/{$moduleId}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->actingAs($intruder)
            ->putJson("/api/modules/{$moduleId}", ['status' => 'completed'])
            ->assertForbidden();
    }
}
