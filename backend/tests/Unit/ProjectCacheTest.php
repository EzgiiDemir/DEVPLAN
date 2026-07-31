<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ProjectCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_tech_stack_only_calls_the_callback_once(): void
    {
        $project = User::factory()->create()->projects()->create(['title' => 'X']);
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['frontend' => 'React', 'backend' => 'Laravel', 'database' => 'PostgreSQL'];
        };

        $first = app(ProjectCache::class)->rememberTechStack($project, $callback);
        $second = app(ProjectCache::class)->rememberTechStack($project, $callback);

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
    }

    public function test_forget_project_context_makes_the_next_call_recompute(): void
    {
        $project = User::factory()->create()->projects()->create(['title' => 'X']);
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['frontend' => '', 'backend' => '', 'database' => ''];
        };

        app(ProjectCache::class)->rememberTechStack($project, $callback);
        app(ProjectCache::class)->forgetProjectContext($project);
        app(ProjectCache::class)->rememberTechStack($project, $callback);

        $this->assertSame(2, $calls);
    }

    public function test_different_projects_have_independent_cache_entries(): void
    {
        $user = User::factory()->create();
        $projectA = $user->projects()->create(['title' => 'A']);
        $projectB = $user->projects()->create(['title' => 'B']);

        app(ProjectCache::class)->rememberTechStack($projectA, fn () => ['frontend' => 'A', 'backend' => '', 'database' => '']);
        app(ProjectCache::class)->rememberTechStack($projectB, fn () => ['frontend' => 'B', 'backend' => '', 'database' => '']);

        $resultA = app(ProjectCache::class)->rememberTechStack($projectA, fn () => ['frontend' => 'STALE', 'backend' => '', 'database' => '']);

        $this->assertSame('A', $resultA['frontend']);
    }

    public function test_forget_codebase_status_is_independent_of_project_context(): void
    {
        $project = User::factory()->create()->projects()->create(['title' => 'X']);
        $statusCalls = 0;
        $contextCalls = 0;

        app(ProjectCache::class)->rememberCodebaseStatus($project, function () use (&$statusCalls) {
            $statusCalls++;

            return ['file_count' => 1, 'dependency_count' => 0, 'last_scanned_at' => null];
        });
        app(ProjectCache::class)->rememberTechStack($project, function () use (&$contextCalls) {
            $contextCalls++;

            return ['frontend' => '', 'backend' => '', 'database' => ''];
        });

        app(ProjectCache::class)->forgetCodebaseStatus($project);

        app(ProjectCache::class)->rememberCodebaseStatus($project, function () use (&$statusCalls) {
            $statusCalls++;

            return ['file_count' => 2, 'dependency_count' => 0, 'last_scanned_at' => null];
        });
        app(ProjectCache::class)->rememberTechStack($project, function () use (&$contextCalls) {
            $contextCalls++;

            return ['frontend' => '', 'backend' => '', 'database' => ''];
        });

        $this->assertSame(2, $statusCalls);
        $this->assertSame(1, $contextCalls);
    }
}
