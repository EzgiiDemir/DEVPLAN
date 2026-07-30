<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_with_no_incoming_id_gets_a_generated_one_back(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/projects');

        $response->assertOk();
        $requestId = $response->headers->get('X-Request-Id');
        $this->assertNotEmpty($requestId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $requestId);
    }

    public function test_an_incoming_request_id_is_echoed_back_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Request-Id' => 'client-supplied-trace-id-123'])
            ->getJson('/api/projects');

        $response->assertOk();
        $this->assertSame('client-supplied-trace-id-123', $response->headers->get('X-Request-Id'));
    }

    public function test_two_separate_requests_get_two_different_generated_ids(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson('/api/projects')->headers->get('X-Request-Id');
        $second = $this->actingAs($user)->getJson('/api/projects')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }
}
