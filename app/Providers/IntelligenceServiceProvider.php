<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\System\NullTechnicalSignalSource;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings of Addis One Intelligence (docs/intelligence-architecture.md).
 * The business domain asks the technical domain only through
 * TechnicalSignalSource; without telemetry the null source answers "unknown".
 */
final class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TechnicalSignalSource::class, NullTechnicalSignalSource::class);
    }
}
