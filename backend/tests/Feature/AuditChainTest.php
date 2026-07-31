<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditChainVerifier;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_recorded_row_gets_a_real_hash_chained_to_the_previous_row(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditLogService::class);

        $first = $audit->record($user, 'auth.login');
        $second = $audit->record($user, 'auth.logout');

        $this->assertNotEmpty($first->hash);
        $this->assertNull($first->previous_hash);
        $this->assertNotEmpty($second->hash);
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertNotSame($first->hash, $second->hash);
    }

    public function test_the_verifier_reports_a_clean_chain_as_valid(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditLogService::class);
        $audit->record($user, 'auth.login');
        $audit->record($user, 'auth.logout');
        $audit->record($user, 'auth.login');

        $result = app(AuditChainVerifier::class)->verify();

        $this->assertTrue($result['valid']);
        $this->assertSame(3, $result['checked']);
        $this->assertNull($result['broken_at_id']);
    }

    public function test_the_verifier_detects_a_tampered_row(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditLogService::class);
        $audit->record($user, 'auth.login');
        $tampered = $audit->record($user, 'auth.logout');
        $audit->record($user, 'auth.login');

        // Simulate someone editing history directly in the database —
        // exactly the scenario a hash chain exists to catch, since a plain
        // UPDATE like this leaves no other trace.
        AuditLog::where('id', $tampered->id)->update(['action' => 'auth.login']);

        $result = app(AuditChainVerifier::class)->verify();

        $this->assertFalse($result['valid']);
        $this->assertSame($tampered->id, $result['broken_at_id']);
    }

    public function test_the_verifier_detects_a_deleted_row(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditLogService::class);
        $audit->record($user, 'auth.login');
        $middle = $audit->record($user, 'auth.logout');
        $last = $audit->record($user, 'auth.login');

        $middle->delete();

        $result = app(AuditChainVerifier::class)->verify();

        $this->assertFalse($result['valid']);
        $this->assertSame($last->id, $result['broken_at_id']);
    }

    public function test_the_verify_console_command_reports_a_valid_chain(): void
    {
        $user = User::factory()->create();
        app(AuditLogService::class)->record($user, 'auth.login');

        $this->artisan('audit:verify')
            ->assertSuccessful()
            ->expectsOutputToContain('no tampering detected');
    }

    public function test_the_verify_console_command_reports_a_broken_chain(): void
    {
        $user = User::factory()->create();
        $log = app(AuditLogService::class)->record($user, 'auth.login');
        AuditLog::where('id', $log->id)->update(['hash' => 'forged']);

        $this->artisan('audit:verify')
            ->assertFailed()
            ->expectsOutputToContain('broken');
    }
}
