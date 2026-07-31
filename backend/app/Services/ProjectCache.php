<?php

namespace App\Services;

use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Caches the handful of project-scoped lookups that get re-computed on
 * every single AI call (tech stack, coding standards) or polled repeatedly
 * by the frontend (codebase status) — previously a real DB round trip every
 * time, even though the underlying data changes rarely. TTLs exist as a
 * safety net; the real staleness guard is explicit invalidation at every
 * write path that can change the cached value (see forgetProjectContext()/
 * forgetCodebaseStatus() call sites).
 *
 * Every method fails open: a cache-store outage (Redis down, network
 * partition) falls back to computing the real value directly rather than
 * throwing — caching is a performance optimization here, not a dependency
 * AI generation or the codebase status endpoint should go down over.
 */
class ProjectCache
{
    private const TECH_STACK_TTL_SECONDS = 300;

    private const CODING_STANDARDS_TTL_SECONDS = 300;

    private const CODEBASE_STATUS_TTL_SECONDS = 30;

    public function rememberTechStack(Project $project, Closure $callback): array
    {
        return $this->remember($this->key($project, 'tech_stack'), self::TECH_STACK_TTL_SECONDS, $callback);
    }

    public function rememberCodingStandards(Project $project, Closure $callback): ?string
    {
        return $this->remember($this->key($project, 'coding_standards'), self::CODING_STANDARDS_TTL_SECONDS, $callback);
    }

    /**
     * @return array{file_count: int, dependency_count: int, last_scanned_at: ?string}
     */
    public function rememberCodebaseStatus(Project $project, Closure $callback): array
    {
        return $this->remember($this->key($project, 'codebase_status'), self::CODEBASE_STATUS_TTL_SECONDS, $callback);
    }

    /**
     * Called wherever the project's tech stack or folder-structure/scaffold
     * selection can change (ModuleItemController's generic store/update/
     * destroy) and wherever new files get indexed (CodebaseIndexer — new
     * symbols/paths change what codingStandards() would derive).
     */
    public function forgetProjectContext(Project $project): void
    {
        $this->forget($this->key($project, 'tech_stack'));
        $this->forget($this->key($project, 'coding_standards'));
    }

    /**
     * Called wherever the indexed file count can change (CodebaseIndexer's
     * indexFiles()/diff()).
     */
    public function forgetCodebaseStatus(Project $project): void
    {
        $this->forget($this->key($project, 'codebase_status'));
    }

    private function remember(string $key, int $ttlSeconds, Closure $callback): mixed
    {
        try {
            return Cache::remember($key, $ttlSeconds, $callback);
        } catch (Throwable $e) {
            Log::warning('cache.store_unavailable', ['key' => $key, 'error' => $e->getMessage()]);

            return $callback();
        }
    }

    private function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            // A stale cache entry that fails to clear is only a performance
            // issue (it'll expire via TTL anyway) — never worth failing the
            // real write this invalidation is attached to.
            Log::warning('cache.store_unavailable', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function key(Project $project, string $suffix): string
    {
        return "project:{$project->id}:{$suffix}";
    }
}
