<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
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
    }
}
