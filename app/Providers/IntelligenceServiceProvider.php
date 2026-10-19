<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\Rules\AuthFailuresRule;
use App\Services\Intelligence\System\Detection\Rules\BackupRule;
use App\Services\Intelligence\System\Detection\Rules\CacheUnavailableRule;
use App\Services\Intelligence\System\Detection\Rules\DbConnectionsRule;
use App\Services\Intelligence\System\Detection\Rules\DbLatencyRule;
use App\Services\Intelligence\System\Detection\Rules\ErrorRepeatRule;
use App\Services\Intelligence\System\Detection\Rules\FailedJobsRule;
use App\Services\Intelligence\System\Detection\Rules\HttpErrorRateRule;
use App\Services\Intelligence\System\Detection\Rules\HttpLatencyRule;
use App\Services\Intelligence\System\Detection\Rules\IntegrationFailureRule;
use App\Services\Intelligence\System\Detection\Rules\QueueBacklogRule;
use App\Services\Intelligence\System\Detection\Rules\RedisUnavailableRule;
use App\Services\Intelligence\System\Detection\Rules\SchedulerMissedRule;
use App\Services\Intelligence\System\Detection\Rules\StorageRule;
use App\Services\Intelligence\System\TelemetrySignalSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings of Addis One Intelligence (docs/intelligence-architecture.md):
 * the detection rules the engine runs, and the telemetry answer the business
 * domain may ask the technical domain for.
 */
final class IntelligenceServiceProvider extends ServiceProvider
{
    /** @var list<class-string<DetectionRule>> */
    public const RULES = [
        HttpErrorRateRule::class,
        HttpLatencyRule::class,
        QueueBacklogRule::class,
        FailedJobsRule::class,
        ErrorRepeatRule::class,
        DbLatencyRule::class,
        DbConnectionsRule::class,
        CacheUnavailableRule::class,
        RedisUnavailableRule::class,
        SchedulerMissedRule::class,
        StorageRule::class,
        BackupRule::class,
        IntegrationFailureRule::class,
        AuthFailuresRule::class,
    ];

    public function register(): void
    {
        $this->app->bind(TechnicalSignalSource::class, TelemetrySignalSource::class);

        $this->app->when(DetectionEngine::class)
            ->needs('$rules')
            ->give(fn (Application $app): array => array_map(fn (string $class) => $app->make($class), self::RULES));
    }
}
