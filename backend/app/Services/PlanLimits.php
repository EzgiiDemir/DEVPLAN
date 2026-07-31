<?php

namespace App\Services;

use App\Models\User;

/**
 * The actual enforcement behind the plan copy shown in Settings ("1
 * project, all 12 modules, basic AI usage" for Free vs. "Unlimited
 * projects" for Pro/Team) — before this, the UI made that claim but nothing
 * checked it; any account could create unlimited projects regardless of
 * plan.
 */
class PlanLimits
{
    // null = unlimited.
    private const PROJECT_LIMITS = [
        'free' => 1,
        'pro' => null,
        'team' => null,
    ];

    public function projectLimitFor(string $plan): ?int
    {
        return array_key_exists($plan, self::PROJECT_LIMITS) ? self::PROJECT_LIMITS[$plan] : 1;
    }

    public function canCreateProject(User $user): bool
    {
        $plan = $user->subscriptions()->firstOrCreate([], ['plan' => 'free'])->plan;
        $limit = $this->projectLimitFor($plan);

        return $limit === null || $user->projects()->count() < $limit;
    }
}
