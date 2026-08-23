<?php

declare(strict_types=1);

namespace CafeteriaSystem\Providers;

use CafeteriaSystem\Services\EuisisApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One configured client for the whole app, built from config/euisis.php.
        $this->app->singleton(EuisisApiClient::class, static fn (): EuisisApiClient => EuisisApiClient::fromConfig());
    }

    public function boot(): void
    {
        // Throttles scan verification per operator (falling back to IP), so a
        // compromised terminal cannot enumerate card tokens.
        RateLimiter::for('cafeteria-scan', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('euisis.scan_rate_limit', 30)
        )->by($request->user('cafeteria')?->getKey() ?? $request->ip()));
    }
}
