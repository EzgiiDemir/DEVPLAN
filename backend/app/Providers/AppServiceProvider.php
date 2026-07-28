<?php

namespace App\Providers;

use App\Services\AiTextGenerator;
use App\Services\AnthropicService;
use App\Services\GroqService;
use Illuminate\Cache\RateLimiting\Limit;
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
    }
}
