<?php

declare(strict_types=1);

use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceRecommendation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\IntelligenceIncidentNotification;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\Detection\IncidentManager;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The detection engine (docs/intelligence-system-health.md): rules read
 * signals, compare with a configured limit or the robust baseline, and the
 * IncidentManager keeps one incident per condition — refreshed while it
 * lasts, mitigated after three quiet runs, resolved after a quiet day.
 */

function seedRequests(CarbonImmutable $now, string $group, int $buckets, int $total, int $failures, int $startMinutesAgo): void
{
    $samples = [];
    for ($i = 0; $i < $buckets; $i++) {
        $at = $now->subMinutes($startMinutesAgo - $i * 5);
        $samples[] = ['metric' => Metrics::REQUEST_TOTAL, 'component' => 'application', 'dimension' => $group, 'value' => $total, 'bucket_start' => $at, 'sample_count' => $total];
        $samples[] = ['metric' => Metrics::REQUEST_5XX, 'component' => 'application', 'dimension' => $group, 'value' => $failures, 'bucket_start' => $at, 'sample_count' => $total];
        $samples[] = ['metric' => Metrics::REQUEST_ERROR_RATE, 'component' => 'application', 'dimension' => $group, 'value' => $total > 0 ? round(100 * $failures / $total, 2) : 0, 'bucket_start' => $at, 'sample_count' => $total];
    }
    app(SignalRepository::class)->write($samples);
}

function setLimit(string $key, int $value): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => $key], ['value' => (string) $value, 'type' => 'integer', 'label_en' => $key, 'label_am' => $key]);
    app(SystemSettingsService::class)->clearCache();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Notification::fake();
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('a server-error spike against a quiet baseline opens one incident with evidence and recommendations', function (): void {
    $now = CarbonImmutable::now();
    // Two days of healthy history at 0.5% errors, then fifteen minutes at 30%.
    seedRequests($now, RouteGroups::PUBLIC_FEEDBACK, 100, 200, 1, 60 * 24 * 2);
    seedRequests($now, RouteGroups::PUBLIC_FEEDBACK, 3, 200, 60, 15);

    $operator = User::factory()->create();
    $role = Role::findOrCreate('intel-ops', 'web');
    $role->syncPermissions([IntelligencePermissions::INCIDENTS]);
    $operator->assignRole($role);

    $result = app(DetectionEngine::class)->run($now);

    expect($result['incidents']['opened'])->toBe(1)->and($result['failed_rules'])->toBe([]);
    $incident = IntelligenceIncident::query()->first();
    expect($incident->rule)->toBe('http_error_rate')
        ->and($incident->severity)->toBe('medium') // baseline-only: no configured limit
        ->and($incident->status)->toBe('open')
        ->and($incident->endpoint)->toBe(RouteGroups::PUBLIC_FEEDBACK)
        ->and($incident->reference)->toMatch('/^INC-\d{4}-\d{4}$/')
        ->and($incident->evidence['params']['failures'])->toBe(180)
        ->and($incident->events()->count())->toBe(2)
        ->and($incident->recommendations()->count())->toBe(4)
        ->and(IntelligenceRecommendation::query()->where('risk', 'high_risk')->count())->toBe(1);

    // Medium is below the default "notify from high": the operator is not disturbed.
    Notification::assertNothingSent();

    // The same condition on the next run is the same incident, not a second one.
    $again = app(DetectionEngine::class)->run($now->addMinutes(5));
    expect($again['incidents']['opened'])->toBe(0)
        ->and($again['incidents']['updated'])->toBe(1)
        ->and(IntelligenceIncident::count())->toBe(1)
        ->and($incident->fresh()->occurrences)->toBe(2);
});

test('a configured limit sets the severity and escalation is recorded once', function (): void {
    $now = CarbonImmutable::now();
    setLimit('http_5xx_rate_percent', 10);
    $operator = User::factory()->create();
    $role = Role::findOrCreate('intel-ops', 'web');
    $role->syncPermissions([IntelligencePermissions::INCIDENTS]);
    $operator->assignRole($role);

    seedRequests($now, RouteGroups::CAFETERIA, 3, 200, 30, 15); // 15% → high
    app(DetectionEngine::class)->run($now);
    $incident = IntelligenceIncident::query()->first();
    expect($incident->severity)->toBe('high');
    Notification::assertSentTo($operator, IntelligenceIncidentNotification::class);

    seedRequests($now, RouteGroups::CAFETERIA, 3, 200, 60, 5); // 30% → critical
    app(DetectionEngine::class)->run($now->addMinutes(5));
    $incident->refresh();
    expect($incident->severity)->toBe('critical')
        ->and($incident->events()->where('event', 'escalated')->count())->toBe(1);
});

test('an incident mitigates after three quiet runs, resolves after a quiet day, and a person closes it', function (): void {
    $now = CarbonImmutable::now();
    setLimit('queue_backlog_jobs', 100);
    app(SignalRepository::class)->write([['metric' => Metrics::QUEUE_PENDING, 'component' => 'queue', 'dimension' => 'notifications', 'value' => 500, 'bucket_start' => $now->subMinutes(5)]]);
    app(DetectionEngine::class)->run($now);
    $incident = IntelligenceIncident::query()->firstOrFail();
    expect($incident->rule)->toBe('queue_backlog')->and($incident->severity)->toBe('critical');

    // The backlog clears: the next runs see a small queue.
    foreach ([5, 10, 15] as $m) {
        app(SignalRepository::class)->write([['metric' => Metrics::QUEUE_PENDING, 'component' => 'queue', 'dimension' => 'notifications', 'value' => 3, 'bucket_start' => $now->addMinutes($m)]]);
        app(DetectionEngine::class)->run($now->addMinutes($m + 1));
    }
    $incident->refresh();
    expect($incident->status)->toBe('mitigated')->and($incident->mitigated_at)->not->toBeNull();

    app(DetectionEngine::class)->run($now->addHours(25));
    $incident->refresh();
    expect($incident->status)->toBe('resolved')->and($incident->verification_status)->toBe('pending');

    $manager = app(IncidentManager::class);
    $manager->transition($incident, 'closed', User::factory()->create(), 'closed');
    expect($incident->fresh()->status)->toBe('closed')
        ->and($incident->events()->pluck('event')->all())->toContain('detected', 'mitigated', 'resolved', 'closed');
});

test('a repeated error fingerprint opens one incident linked to the fingerprint', function (): void {
    $now = CarbonImmutable::now();
    $fp = IntelligenceErrorFingerprint::query()->create([
        'fingerprint' => sha1('x'), 'exception_class' => 'RuntimeException', 'message_normalized' => 'boom {n}', 'route_group' => RouteGroups::EMPLOYEE_PORTAL,
        'component' => 'Services/Employees', 'occurrences' => 12, 'first_seen_at' => $now->subMinutes(20), 'last_seen_at' => $now->subMinute(),
    ]);

    app(DetectionEngine::class)->run($now);
    $incident = IntelligenceIncident::query()->where('rule', 'error_repeat')->firstOrFail();
    expect($incident->severity)->toBe('medium')
        ->and($incident->endpoint)->toBe(RouteGroups::EMPLOYEE_PORTAL)
        ->and($fp->fresh()->incident_id)->toBe($incident->id)
        ->and(json_encode($incident->evidence))->not->toContain('hunter');
});

test('nothing collected means no incident, never a fake one', function (): void {
    $result = app(DetectionEngine::class)->run(CarbonImmutable::now());

    expect($result['findings'])->toBe(0)->and(IntelligenceIncident::count())->toBe(0)->and($result['failed_rules'])->toBe([]);
});
