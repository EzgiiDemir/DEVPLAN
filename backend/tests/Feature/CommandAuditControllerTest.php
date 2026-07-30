<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CommandAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_developer_can_relay_a_real_executed_command(): void
    {
        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Audit Test'])->json();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project['id']}/audit/commands", [
            'type' => 'command',
            'command' => 'git push --force',
            'risk_level' => 'dangerous',
            'exit_code' => 0,
        ]);

        $response->assertNoContent();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'project_id' => $project['id'],
            'action' => 'companion.command_executed',
        ]);
    }

    public function test_a_relayed_file_delete_is_recorded_as_its_own_action(): void
    {
        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Audit Test'])->json();

        $this->actingAs($user)->postJson("/api/projects/{$project['id']}/audit/commands", [
            'type' => 'file_delete',
            'path' => 'src/old-file.js',
            'risk_level' => 'sensitive',
        ])->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'companion.file_deleted']);
    }

    /**
     * Covers Subsystem 12 (Production Logging): a dangerous/sensitive
     * command additionally writes a structured security log line, not just
     * the durable audit_logs row.
     */
    public function test_a_dangerous_command_writes_a_structured_security_log(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Audit Test'])->json();

        $this->actingAs($user)->postJson("/api/projects/{$project['id']}/audit/commands", [
            'type' => 'command',
            'command' => 'git push --force',
            'risk_level' => 'dangerous',
            'exit_code' => 0,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => $message === 'security.risky_command_executed' && $context['risk_level'] === 'dangerous')
            ->once();
    }

    public function test_a_safe_command_does_not_write_a_security_log(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $project = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Audit Test'])->json();

        $this->actingAs($user)->postJson("/api/projects/{$project['id']}/audit/commands", [
            'type' => 'command',
            'command' => 'npm install',
            'risk_level' => 'safe',
        ]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_viewer_cannot_relay_command_executions(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::create(['name' => 'Acme Inc', 'personal' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $project = $owner->projects()->create(['team_id' => $team->id, 'title' => 'Audit Test']);

        $this->actingAs($viewer)->postJson("/api/projects/{$project->id}/audit/commands", [
            'type' => 'command',
            'command' => 'npm install',
            'risk_level' => 'safe',
        ])->assertForbidden();
    }
}
