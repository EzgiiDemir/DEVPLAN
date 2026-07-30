<?php

namespace App\Providers;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Services\AiTextGenerator;
use App\Services\AnthropicService;
use App\Services\GroqService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiTextGenerator::class, function ($app) {
            return config('services.ai_provider') === 'groq'
                ? $app->make(GroqService::class)
                : $app->make(AnthropicService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AI calls hit a paid external provider — cap them per user so a runaway
        // frontend loop or a malicious script can't burn through API budget.
        RateLimiter::for('ai', function ($request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Unauthenticated auth endpoints: throttle by IP to blunt brute-force
        // login attempts and registration spam.
        RateLimiter::for('auth-attempts', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Comments are polymorphic (Project or FeatureRequest); a morph map
        // keeps commentable_type as a clean, stable API value instead of a
        // leaked fully-qualified class name.
        Relation::morphMap([
            'project' => Project::class,
            'feature_request' => FeatureRequest::class,
        ]);
    }
}
