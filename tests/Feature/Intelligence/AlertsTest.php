<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\IntelligenceAlert;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\User;
use App\Services\Intelligence\Alerts\AlertEngine;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\SituationRoomScenario;

/*
 * The alert engine (docs/intelligence-alerts.md): one alert per source
 * condition, occurrences on repeats, resolution after CLEAR_RUNS quiet runs,
 * a cooldown before the same condition re-fires, and a page whose
 * visibility follows the two domains.
 */

function alertUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('alert-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

function alertIncident(): IntelligenceIncident
{
    $now = CarbonImmutable::now();
    IntelligenceErrorFingerprint::query()->create([
        'fingerprint' => sha1('alerts'), 'exception_class' => 'RuntimeException', 'message_normalized' => 'font {value} missing', 'route_group' => RouteGroups::ADMIN,
        'route_name' => 'id-cards.pdf', 'component' => 'Services/IdCards', 'occurrences' => 15, 'first_seen_at' => $now->subMinutes(20), 'last_seen_at' => $now->subMinute(),
        'sample_context' => ['file' => 'app/Services/IdCards/Renderer.php', 'line' => 42, 'frames' => []],
    ]);
    app(SignalRepository::class)->write([['metric' => Metrics::SCHEDULER_HEARTBEAT, 'component' => 'scheduler', 'value' => 1, 'bucket_start' => $now->subMinutes(5)]]);
    app(DetectionEngine::class)->run($now);

    return IntelligenceIncident::query()->where('rule', 'error_repeat')->firstOrFail();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Notification::fake();
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('an active incident becomes one technical alert that repeats, resolves after quiet runs and respects the cooldown', function (): void {
    $incident = alertIncident();
    $engine = app(AlertEngine::class);
    $now = CarbonImmutable::now();

    $first = $engine->run($now);
    expect($first['opened'])->toBe(1)->and($first['skipped_business'])->toBeTrue();
    $alert = IntelligenceAlert::query()->where('domain', 'technical')->firstOrFail();
    expect($alert->source_key)->toBe('incident:'.$incident->fingerprint)->and($alert->status)->toBe('open')->and($alert->occurrences)->toBe(1)
        ->and($alert->href)->toBe(route('intelligence.incidents.show', $incident->id));

    $second = $engine->run($now->addMinutes(5));
    expect($second['opened'])->toBe(0)->and($second['updated'])->toBe(1)->and($alert->refresh()->occurrences)->toBe(2)
        ->and(IntelligenceAlert::query()->count())->toBe(1);

    // The condition stops: closed incident → clear runs count up, then resolve with a cooldown.
    $incident->forceFill(['status' => 'closed'])->save();
    foreach (range(1, AlertEngine::CLEAR_RUNS) as $i) {
        $engine->run($now->addMinutes(10 * $i + 10));
    }
    expect($alert->refresh()->status)->toBe('resolved')->and($alert->cooldown_until)->not->toBeNull();

    // Back within the cooldown: no new alert; after it: a new one.
    $incident->forceFill(['status' => 'open'])->save();
    $engine->run($now->addMinutes(50));
    expect(IntelligenceAlert::query()->count())->toBe(1);
    $engine->run($now->addMinutes(200));
    expect(IntelligenceAlert::query()->count())->toBe(2)->and(IntelligenceAlert::query()->where('status', 'open')->count())->toBe(1);
});

test('business alerts are computed city-wide for the first Super Admin and the run survives an area failure', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $admin = alertUser([IntelligencePermissions::VIEW]);
    $admin->assignRole('Super Admin');

    $result = app(AlertEngine::class)->run();
    expect($result['skipped_business'])->toBeFalse()->and($result['failed_areas'])->toBe([]);
    expect(IntelligenceAlert::query()->where('domain', 'business')->get())->each(fn ($a) => $a->severity->toBeIn(['medium', 'high', 'critical']));
});

test('the alerts page shows each domain only to its grants, and acknowledging is audited', function (): void {
    $incident = alertIncident();
    app(AlertEngine::class)->run();
    IntelligenceAlert::query()->create([
        'type' => 'insight', 'domain' => 'business', 'source_key' => 'insight:workforce:x', 'severity' => 'high', 'status' => 'open', 'title' => 'intelligence.insights.x',
        'params' => [], 'evidence' => [], 'area' => 'workforce', 'occurrences' => 1, 'clear_runs' => 0, 'first_detected_at' => now(), 'last_detected_at' => now(),
    ]);

    $technician = alertUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS, IntelligencePermissions::INCIDENTS_MANAGE]);
    $scopedExecutive = SituationRoomScenario::build()->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE], 'subcity_a', OrganizationScopeType::Self);
    $cityExecutive = alertUser([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE]);

    $this->actingAs($technician)->get(route('intelligence.alerts.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Alerts/Index')
            ->where('options.domains', ['technical'])
            ->has('alerts.data', 1)
            ->where('alerts.data.0.domain', 'technical')
            ->where('alerts.data.0.can_manage', true));

    // An executive with a restricted scope sees no business alert (city-wide figures) and no technical one.
    $this->actingAs($scopedExecutive)->get(route('intelligence.alerts.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.domains', [])->has('alerts.data', 0));
    $this->actingAs($cityExecutive)->get(route('intelligence.alerts.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.domains', ['business'])->has('alerts.data', 1)->where('alerts.data.0.domain', 'business')->where('alerts.data.0.can_manage', true));

    $alert = IntelligenceAlert::query()->where('domain', 'technical')->firstOrFail();
    $this->actingAs($technician)->patch(route('intelligence.alerts.update', $alert), ['action' => 'acknowledge'])->assertRedirect();
    expect($alert->refresh()->status)->toBe('acknowledged')->and($alert->acknowledged_by)->toBe($technician->id)
        ->and(AuditLog::query()->where('event_type', AuditEventType::IntelligenceAlertUpdated->value)->count())->toBe(1);

    $business = IntelligenceAlert::query()->where('domain', 'business')->firstOrFail();
    $this->actingAs($technician)->patch(route('intelligence.alerts.update', $business), ['action' => 'resolve'])->assertForbidden();
    expect($incident->refresh()->status)->toBe('open');
});
