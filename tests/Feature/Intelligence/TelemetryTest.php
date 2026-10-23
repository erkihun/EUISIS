<?php

declare(strict_types=1);

use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceSignal;
use App\Models\SystemSetting;
use App\Services\ErrorLoggingService;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\RequestCounters;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\Intelligence\System\Telemetry\TelemetryCollector;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Platform telemetry (docs/intelligence-system-health.md): request counters
 * live in the cache and reach the database only through the collector;
 * probes are isolated; unhandled exceptions become counted fingerprints with
 * no secret in them.
 */

beforeEach(function (): void {
    config(['cache.default' => 'array', 'intelligence.telemetry.sample_every' => 1]);
});

test('request counters count failures exactly, totals by sample, then drain and forget', function (): void {
    $counters = app(RequestCounters::class);
    $minute = $counters->minuteKey(time()) - 120;

    $counters->record(RouteGroups::PUBLIC_FEEDBACK, 200, 120, $minute);
    $counters->record(RouteGroups::PUBLIC_FEEDBACK, 500, 2500, $minute);
    $counters->record(RouteGroups::PUBLIC_FEEDBACK, 403, 50, $minute);
    $counters->record('not-a-group', 200, 10, $minute);

    $drained = $counters->drain([$minute]);
    $pf = $drained[$minute][RouteGroups::PUBLIC_FEEDBACK];

    expect($pf['total'])->toBe(3)
        ->and($pf['s5xx'])->toBe(1)
        ->and($pf['s4xx'])->toBe(1)
        ->and($pf['s403'])->toBe(1)
        ->and($pf['s2xx'])->toBe(1)
        ->and($pf['slow'])->toBe(1)
        ->and($pf['b100'])->toBe(1)
        ->and($pf['b300'])->toBe(1)
        ->and($pf['b3000'])->toBe(1)
        ->and($pf['dur_sum'])->toBe(2670)
        ->and($drained[$minute][RouteGroups::OTHER]['total'])->toBe(1)
        ->and($counters->drain([$minute]))->toBe([]);

    config(['intelligence.telemetry.sample_every' => 0, 'cache.default' => 'database']);
    expect((new RequestCounters)->sampleEvery())->toBe(20);
});

test('the collector flushes request counters into five-minute buckets and probes the platform', function (): void {
    $counters = app(RequestCounters::class);
    $now = CarbonImmutable::now()->setSecond(0);
    $minute = $counters->minuteKey($now->getTimestamp()) - 180;
    foreach (range(1, 25) as $i) {
        $counters->record(RouteGroups::DAILY_ACTIVITY, $i <= 5 ? 500 : 200, $i * 10, $minute);
    }

    DB::table('jobs')->insert(['queue' => 'notifications', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now->subHours(2)->getTimestamp(), 'created_at' => $now->subHours(2)->getTimestamp()]);

    $result = app(TelemetryCollector::class)->collect($now);

    expect($result['written'])->toBeGreaterThan(10)
        ->and($result['failed'])->not->toContain('requests')->not->toContain('queue')->not->toContain('scheduler');

    $repo = app(SignalRepository::class);
    expect($repo->sum(Metrics::REQUEST_TOTAL, RouteGroups::DAILY_ACTIVITY, $now->subHour()))->toBe(25.0)
        ->and($repo->sum(Metrics::REQUEST_5XX, RouteGroups::DAILY_ACTIVITY, $now->subHour()))->toBe(5.0)
        ->and($repo->latest(Metrics::REQUEST_ERROR_RATE, RouteGroups::DAILY_ACTIVITY)['value'])->toBe(20.0)
        ->and($repo->latest(Metrics::QUEUE_PENDING, 'notifications')['value'])->toBe(1.0)
        ->and($repo->latest(Metrics::QUEUE_OLDEST_AGE_SECONDS, 'notifications')['value'])->toBeGreaterThanOrEqual(7190.0)
        ->and($repo->latest(Metrics::SCHEDULER_HEARTBEAT)['value'])->toBe(1.0)
        ->and($repo->latest(Metrics::DB_PING_MS))->not->toBeNull()
        ->and($repo->latest(Metrics::CACHE_AVAILABLE)['value'])->toBe(1.0)
        ->and($repo->latest(Metrics::STORAGE_WRITABLE)['value'])->toBe(1.0)
        // SQLite exposes no connection statistics and Redis is not configured: unknown, not fabricated.
        ->and(DB::getDriverName() === 'sqlite' ? $repo->latest(Metrics::DB_CONNECTIONS_PERCENT) : null)->toBeNull()
        ->and($repo->latest(Metrics::REDIS_AVAILABLE))->toBeNull()
        ->and($counters->drain([$minute]))->toBe([]);
});

test('an unhandled exception becomes one counted fingerprint without secrets', function (): void {
    $request = Request::create('/service-feedback/abc', 'POST', ['password' => 'hunter2', 'comment' => 'x']);
    $logger = app(ErrorLoggingService::class);

    $logger->log(new RuntimeException('Record 0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b not found (attempt 3)'), $request);
    $logger->log(new RuntimeException('Record 0199ffff-3c4d-7e5f-8a9b-0c1d2e3f4a5b not found (attempt 4)'), $request);
    $logger->log(new LogicException('Something else'), $request);

    expect(IntelligenceErrorFingerprint::count())->toBe(2);
    $fp = IntelligenceErrorFingerprint::query()->where('exception_class', RuntimeException::class)->first();
    expect($fp->occurrences)->toBe(2)
        ->and($fp->route_group)->toBe(RouteGroups::PUBLIC_FEEDBACK)
        ->and($fp->message_normalized)->toContain('{uuid}')->toContain('{n}')->not->toContain('0199a1b2')
        ->and(json_encode($fp->sample_context))->not->toContain('hunter2')->not->toContain('password')
        ->and($fp->last_error_id)->toStartWith('ERR-');
});

test('telemetry off records nothing', function (): void {
    config(['cache.default' => 'array']);
    SystemSetting::query()->create(['group' => 'intelligence', 'key' => 'telemetry_enabled', 'value' => '0', 'type' => 'boolean', 'label_en' => 'x', 'label_am' => 'x']);
    app(SystemSettingsService::class)->clearCache();

    app(ErrorLoggingService::class)->log(new RuntimeException('boom'), Request::create('/x'));
    expect(IntelligenceErrorFingerprint::count())->toBe(0);

    $this->artisan('intelligence:collect')->expectsOutputToContain('telemetry is off')->assertSuccessful();
    expect(IntelligenceSignal::count())->toBe(0);
});

test('request buckets land in the app timezone, so recent windows include them outside UTC', function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('Africa/Addis_Ababa');
    config(['app.timezone' => 'Africa/Addis_Ababa']);

    try {
        $counters = app(RequestCounters::class);
        $now = CarbonImmutable::now()->setSecond(0);
        foreach (range(1, 4) as $i) {
            $counters->record(RouteGroups::DAILY_ACTIVITY, 200, 10, $counters->minuteKey($now->getTimestamp()) - 180);
        }

        app(TelemetryCollector::class)->collect($now);

        expect(app(SignalRepository::class)->sum(Metrics::REQUEST_TOTAL, RouteGroups::DAILY_ACTIVITY, $now->subHour()))->toBe(4.0);
    } finally {
        date_default_timezone_set($previous);
        config(['app.timezone' => $previous]);
    }
});
