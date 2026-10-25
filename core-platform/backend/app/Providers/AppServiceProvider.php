<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One scope cache per request or job.
        $this->app->scoped(OrganizationScope::class);
    }

    public function boot(): void
    {
        // migrate:fresh / refresh / reset and db:wipe refuse to run in production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Per-account limits from Core settings › Security.
        RateLimiter::for('core-api', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.api_rate_limit_per_minute'))
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('core-verify', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.verification_rate_limit_per_minute'))
            ->by('verify:'.($request->user()?->getKey() ?? $request->ip())));
    }
}
