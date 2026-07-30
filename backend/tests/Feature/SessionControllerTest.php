<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The suite defaults to the 'array' session driver for speed/isolation
     * — real, listable rows in the `sessions` table require the same
     * 'database' driver production actually uses, so this test class opts
     * into it specifically rather than testing against a driver nothing
     * real ever runs on.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    /**
     * Confirmed by experiment: Laravel's HTTP test helpers don't reproduce a
     * real browser's cookie jar between sequential calls (each call, absent
     * an explicit matching cookie, gets a brand-new session id — the id a
     * test reads via session()->getId() before a request doesn't match the
     * id that request's own session middleware ends up using). Seeding a
     * row under a fixed id and asserting on it directly still exercises the
     * real controller logic (index/destroy/destroyOthers, ownership,
     * counting) end-to-end; only "does the CURRENT request's own session
     * show up as current, and is revoking it rejected" specifically needs a
     * real cross-request cookie round-trip, which is covered instead by
     * Phase 11's live end-to-end script against the real running server
     * (a real cookie jar across real HTTP calls, same as Phase 9/10's
     * live verification scripts).
     */
    private function seedSession(string $id, User $user, string $userAgent = 'PHPUnit'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    public function test_listing_shows_all_of_a_users_real_sessions(): void
    {
        $user = User::factory()->create();
        $this->seedSession('session-a', $user, 'Chrome on Mac');
        $this->seedSession('session-b', $user, 'Safari on iPhone');

        $response = $this->actingAs($user)->getJson('/api/security/sessions');
        $response->assertOk()->assertJsonCount(2);
        $agents = collect($response->json())->pluck('user_agent');
        $this->assertTrue($agents->contains('Chrome on Mac'));
        $this->assertTrue($agents->contains('Safari on iPhone'));
    }

    public function test_revoking_a_real_session_deletes_it_and_writes_an_audit_row(): void
    {
        $user = User::factory()->create();
        $this->seedSession('session-a', $user);

        $this->actingAs($user)->deleteJson('/api/security/sessions/session-a')->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'session-a']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.session_revoked']);
    }

    public function test_revoke_others_deletes_every_session_except_the_one_named(): void
    {
        $user = User::factory()->create();
        $this->seedSession('keep-me', $user);
        $this->seedSession('device-2', $user);
        $this->seedSession('device-3', $user);

        // destroyOthers() treats the request's own current session as the
        // one to keep; since that id isn't any of the 3 seeded here, this
        // exercises the "delete everything for this user except one id"
        // mechanics directly rather than asserting which specific id
        // survives. The accounting identity (revoked + remaining == the
        // original count, or one more if the request's own fresh session
        // also got persisted) holds regardless of which id is "current".
        $response = $this->actingAs($user)->deleteJson('/api/security/sessions/others');
        $response->assertOk();

        $remaining = DB::table('sessions')->where('user_id', $user->id)->count();
        $this->assertLessThanOrEqual(1, $remaining);
        $this->assertGreaterThanOrEqual(2, $response->json('revoked'));
    }

    public function test_a_user_cannot_see_or_revoke_another_users_session(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->seedSession('user-b-session', $userB);

        $this->actingAs($userA)->getJson('/api/security/sessions')->assertOk()->assertJsonCount(0);
        $this->actingAs($userA)->deleteJson('/api/security/sessions/user-b-session')->assertStatus(404);
        $this->assertDatabaseHas('sessions', ['id' => 'user-b-session']);
    }
}
