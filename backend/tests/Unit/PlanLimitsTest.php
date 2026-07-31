<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_free_user_with_no_projects_can_create_one(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(app(PlanLimits::class)->canCreateProject($user));
    }

    public function test_a_free_user_with_one_project_already_cannot_create_a_second(): void
    {
        $user = User::factory()->create();
        $user->projects()->create(['title' => 'First project']);

        $this->assertFalse(app(PlanLimits::class)->canCreateProject($user));
    }

    public function test_a_pro_user_can_create_unlimited_projects(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['plan' => 'pro']);
        for ($i = 0; $i < 5; $i++) {
            $user->projects()->create(['title' => "Project {$i}"]);
        }

        $this->assertTrue(app(PlanLimits::class)->canCreateProject($user));
    }

    public function test_a_team_user_can_create_unlimited_projects(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['plan' => 'team']);
        $user->projects()->create(['title' => 'First project']);

        $this->assertTrue(app(PlanLimits::class)->canCreateProject($user));
    }

    public function test_a_user_with_no_subscription_row_yet_is_treated_as_free(): void
    {
        $user = User::factory()->create();
        $user->projects()->create(['title' => 'First project']);

        $this->assertFalse(app(PlanLimits::class)->canCreateProject($user));
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'free']);
    }
}
