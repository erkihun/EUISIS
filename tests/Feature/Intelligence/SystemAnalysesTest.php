<?php

declare(strict_types=1);

use App\Models\IntelligenceDeployment;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\User;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceService;
use App\Services\Intelligence\System\ComponentHealth;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\Intelligence\System\TelemetrySignalSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The technical areas: every panel reads collected telemetry only; nothing
 * collected is UNKNOWN, telemetry off is TELEMETRY_OFF, and no business or
 * personal figure appears.
 */

function techUser(array $extra = []): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('tech-'.md5(implode(',', $extra)), 'web');
    $role->syncPermissions([IntelligencePermissions::VIEW, ...$extra]);
    $user->assignRole($role);

    return $user;
}

function areaPayload(User $user, IntelligenceArea $area): array
{
    $service = app(IntelligenceService::class);
    $context = $service->context($user, Request::create('/intelligence/'.$area->value));

    return $service->area($area, $context)['payload'];
}

function panel(array $payload, string $key): array
{
    foreach ($payload['panels'] as $panel) {
        if ($panel['key'] === $key) {
            return $panel;
        }
    }
    throw new RuntimeException("panel {$key} missing: ".implode(',', array_column($payload['panels'], 'key')));
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Notification::fake();
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('without telemetry every component is unknown and panels say so', function (): void {
    $states = app(ComponentHealth::class)->states();
    expect(collect($states)->pluck('state')->unique()->all())->toBe(['unknown']);

    $payload = areaPayload(techUser([IntelligencePermissions::SYSTEM_HEALTH]), IntelligenceArea::SystemHealth);
    expect(panel($payload, 'component_states')['type'])->toBe('table')
        ->and(panel($payload, 'error_rate_trend')['reason'])->toBe('NO_DATA')
        ->and($payload['has_errors'])->toBeFalse()
        ->and($payload['insights'])->toBe([]);

    $rows = panel($payload, 'component_states')['data']['rows'];
    expect(collect($rows)->pluck('cells.state.key')->unique()->all())->toBe(['intelligence.health.unknown']);
});

test('system health reads the collected samples and raises components with active incidents', function (): void {
    $now = CarbonImmutable::now();
    $repo = app(SignalRepository::class);
    $bucket = $now->subMinutes(5);
    $repo->write([
        ['metric' => Metrics::DB_PING_MS, 'component' => 'database', 'value' => 2.4, 'bucket_start' => $bucket],
        ['metric' => Metrics::CACHE_AVAILABLE, 'component' => 'cache', 'value' => 1, 'bucket_start' => $bucket],
        ['metric' => Metrics::STORAGE_WRITABLE, 'component' => 'storage', 'value' => 1, 'bucket_start' => $bucket],
        ['metric' => Metrics::STORAGE_FREE_PERCENT, 'component' => 'storage', 'value' => 7, 'bucket_start' => $bucket],
        ['metric' => Metrics::SCHEDULER_HEARTBEAT, 'component' => 'scheduler', 'value' => 1, 'bucket_start' => $now->subMinutes(40)],
        ['metric' => Metrics::QUEUE_PENDING, 'component' => 'queue', 'dimension' => 'default', 'value' => 3, 'bucket_start' => $bucket],
        ['metric' => Metrics::REQUEST_TOTAL, 'component' => 'application', 'dimension' => RouteGroups::ADMIN, 'value' => 100, 'bucket_start' => $bucket],
        ['metric' => Metrics::REQUEST_5XX, 'component' => 'application', 'dimension' => RouteGroups::ADMIN, 'value' => 1, 'bucket_start' => $bucket],
    ]);
    app(DetectionEngine::class)->run($now); // storage at 7% free → high incident

    $states = app(ComponentHealth::class)->states($now);
    expect($states['database']['state'])->toBe('healthy')
        ->and($states['cache']['state'])->toBe('healthy')
        ->and($states['storage']['state'])->toBe('unhealthy') // degraded by free space, raised by the incident
        ->and($states['storage']['incidents'])->toBe(1)
        ->and($states['scheduler']['state'])->toBe('unhealthy') // 40 min without a heartbeat: degraded by sample, raised by the scheduler_missed incident
        ->and($states['scheduler']['incidents'])->toBe(1)
        ->and($states['redis']['state'])->toBe('unknown')
        ->and($states['backups']['state'])->toBe('unknown')
        ->and($states['application']['detail']['error_rate_percent'])->toBe(1.0);

    $payload = areaPayload(techUser([IntelligencePermissions::SYSTEM_HEALTH]), IntelligenceArea::SystemHealth);
    $headline = panel($payload, 'system_headline');
    $kpi = collect($headline['data']['items'])->keyBy('key');
    expect($kpi['incidents_high']['value'])->toBe(2) // storage (7 % free) and scheduler (40 min silent)
        ->and($kpi['components_unknown']['value'])->toBeGreaterThan(0)
        ->and($payload['insights'][0]['severity'])->toBe('high')
        ->and($payload['insights'][0]['href'])->toContain('/intelligence/incidents/');
});

test('errors, application, database, queue, integrations, deployments and predictive areas render from signals', function (): void {
    $now = CarbonImmutable::now();
    $repo = app(SignalRepository::class);
    $samples = [];
    // A day of hourly-ish samples: latency rising on the cafeteria group, a steady admin group.
    for ($i = 24; $i >= 1; $i--) {
        $at = $now->subHours($i);
        $samples[] = ['metric' => Metrics::REQUEST_TOTAL, 'component' => 'application', 'dimension' => RouteGroups::CAFETERIA, 'value' => 400, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::REQUEST_5XX, 'component' => 'application', 'dimension' => RouteGroups::CAFETERIA, 'value' => 4, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::REQUEST_LATENCY_P95_MS, 'component' => 'application', 'dimension' => RouteGroups::CAFETERIA, 'value' => 100 + (24 - $i) * 60, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::REQUEST_TOTAL, 'component' => 'application', 'dimension' => RouteGroups::ADMIN, 'value' => 100, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::DB_PING_MS, 'component' => 'database', 'value' => 1.5, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::QUEUE_PENDING, 'component' => 'queue', 'dimension' => 'notifications', 'value' => 10 + (24 - $i) * 8, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::QUEUE_FAILED_5M, 'component' => 'queue', 'value' => 0, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::INTEGRATION_CALLS, 'component' => 'integrations', 'dimension' => 'sms', 'value' => 5, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::INTEGRATION_FAILURES, 'component' => 'integrations', 'dimension' => 'sms', 'value' => $i < 3 ? 5 : 0, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::INTEGRATION_API_REQUESTS, 'component' => 'integrations', 'value' => 20, 'bucket_start' => $at];
        $samples[] = ['metric' => Metrics::INTEGRATION_API_FAILURES, 'component' => 'integrations', 'value' => 1, 'bucket_start' => $at];
    }
    $repo->write($samples);
    IntelligenceErrorFingerprint::query()->create(['fingerprint' => sha1('a'), 'exception_class' => 'App\\X\\BoomException', 'message_normalized' => 'boom {n}', 'route_group' => RouteGroups::CAFETERIA, 'component' => 'Services/Cafeteria', 'occurrences' => 7, 'first_seen_at' => $now->subHours(3), 'last_seen_at' => $now->subMinutes(10)]);
    $deployment = IntelligenceDeployment::query()->create(['release' => 'v1.9.0', 'commit_hash' => 'abc1234def', 'environment' => (string) app()->environment(), 'deployed_at' => $now->subMinutes(30)]);

    $developer = techUser([IntelligencePermissions::DIAGNOSTICS, IntelligencePermissions::APPLICATION_HEALTH, IntelligencePermissions::DATABASE_HEALTH, IntelligencePermissions::QUEUE_HEALTH, IntelligencePermissions::INTEGRATION_HEALTH, IntelligencePermissions::DEPLOYMENT_ANALYSIS]);

    $errors = areaPayload($developer, IntelligenceArea::Errors);
    expect(collect(panel($errors, 'error_headline')['data']['items'])->keyBy('key')['fingerprints_active']['value'])->toBe(1)
        ->and(panel($errors, 'top_fingerprints')['data']['rows'][0]['cells']['exception'])->toBe('BoomException')
        ->and(json_encode($errors))->not->toContain('password');

    $app = areaPayload($developer, IntelligenceArea::ApplicationHealth);
    $appKpi = collect(panel($app, 'app_headline')['data']['items'])->keyBy('key');
    expect($appKpi['requests_total']['value'])->toBe(12000)
        ->and($appKpi['error_rate']['value'])->toBe(0.8)
        ->and(panel($app, 'latency_trend')['type'])->toBe('line')
        ->and(collect($app['insights'])->pluck('key')->all())->toContain('latency_deteriorating')
        ->and(collect($app['insights'])->firstWhere('key', 'latency_deteriorating')['confidence'])->toBe('high');

    $db = areaPayload($developer, IntelligenceArea::DatabaseHealth);
    expect(panel($db, 'db_ping_trend')['type'])->toBe('line')
        ->and(panel($db, 'db_connections_trend')['reason'])->toBe('UNKNOWN') // never collected on SQLite
        ->and($db['insights'])->toBe([]);

    $queue = areaPayload($developer, IntelligenceArea::QueueHealth);
    expect(collect($queue['insights'])->firstWhere('key', 'queue_growing')['title_params']->queue ?? collect($queue['insights'])->firstWhere('key', 'queue_growing')['title_params']['queue'])->toBe('notifications')
        ->and(panel($queue, 'queue_pending_trend')['type'])->toBe('line');

    $integrations = areaPayload($developer, IntelligenceArea::IntegrationHealth);
    $row = panel($integrations, 'integrations_table')['data']['rows'][0]['cells'];
    expect($row['provider'])->toBe('sms')->and($row['calls'])->toBe(120)->and($row['failures'])->toBe(10)->and($row['last_success'])->not->toBeNull()
        ->and(panel($integrations, 'email_delivery')['reason'])->toBe('NEEDS_DATA');

    $deployments = areaPayload($developer, IntelligenceArea::DeploymentAnalysis);
    expect(panel($deployments, 'deployments_table')['data']['rows'][0]['cells']['release'])->toBe('v1.9.0')
        ->and(panel($deployments, 'deployments_table')['data']['rows'][0]['cells']['commit'])->toBe('abc1234def');

    $predictive = areaPayload($developer, IntelligenceArea::Predictive);
    $verdicts = collect(panel($predictive, 'trend_watch')['data']['rows'])->mapWithKeys(fn (array $r): array => [$r['key'] => $r['cells']['verdict']['key']]);
    expect($verdicts['queue_notifications'])->toBe('intelligence.values.trend_verdict.deteriorating')
        ->and($verdicts['db_ping'])->toBe('intelligence.values.trend_verdict.stable')
        ->and($verdicts['db_connections'])->toBe('intelligence.values.trend_verdict.insufficient')
        ->and(collect($predictive['insights'])->pluck('key')->unique()->all())->toBe(['trend_deteriorating']);

    // The telemetry answer the business domain may ask for.
    $answer = app(TelemetrySignalSource::class)->failuresFor(RouteGroups::CAFETERIA, $now->subDay(), $now);
    expect($answer['known'])->toBeTrue()->and($answer['requests'])->toBe(9600)->and($answer['failures'])->toBe(96)->and($answer['failure_rate_percent'])->toBe(1.0)
        ->and(app(TelemetrySignalSource::class)->failuresFor(RouteGroups::TRANSFERS, $now->subDay(), $now)['known'])->toBeFalse();

    // Deployment correlation on an incident opened after the release.
    app(SignalRepository::class)->write([['metric' => Metrics::CACHE_AVAILABLE, 'component' => 'cache', 'value' => 0, 'bucket_start' => $now->subMinutes(5)]]);
    app(DetectionEngine::class)->run($now);
    expect(IntelligenceIncident::query()->where('rule', 'cache_unavailable')->value('deployment_id'))->toBe($deployment->id);
});

test('a deployment-less installation says so instead of guessing, and the area pages render over HTTP', function (): void {
    $developer = techUser([IntelligencePermissions::DEPLOYMENT_ANALYSIS, IntelligencePermissions::SYSTEM_HEALTH]);
    $payload = areaPayload($developer, IntelligenceArea::DeploymentAnalysis);
    expect(panel($payload, 'deployment_headline')['reason'])->toBe('NEEDS_DEPLOYMENT_CONFIGURATION');

    $this->actingAs($developer)->get(route('intelligence.area', 'system_health'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Area')->has('labels.en')->has('scope')->has('filters')->where('area.key', 'system_health'));
});
