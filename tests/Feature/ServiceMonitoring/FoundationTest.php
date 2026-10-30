<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Foundation (phase 1): the Ethiopian fiscal year and its quarters (D2), the
 * default schedule (D5), the participant registry (D7), permissions and
 * roles, and the feature flag.
 */

beforeEach(function (): void {
    $this->s = ServiceMonitoringScenario::build();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('builds FY 2019 E.C. from Hamle 1, 2018 to Sene 30, 2019 with Pagume in the first quarter', function (): void {
    $calendar = app(FiscalCalendar::class);
    [$start, $end] = $calendar->fiscalYear(2019);
    expect($start->toDateString())->toBe('2026-07-08')
        ->and($end->toDateString())->toBe('2027-07-07');

    // Q1 = Hamle, Nehase, Pagume, Meskerem: Pagume 2018 (2026-09-06..09-10) lies inside it.
    [$q1Start, $q1End] = $calendar->quarter(2019, 1);
    expect($q1Start->toDateString())->toBe('2026-07-08')
        ->and($q1End->toDateString())->toBe('2026-10-10')
        ->and('2026-09-08')->toBeGreaterThan($q1Start->toDateString())->toBeLessThan($q1End->toDateString());

    // Q2 starts on Tikimt 1, the day after Meskerem 30; Q4 ends with the fiscal year.
    expect($calendar->quarter(2019, 2)[0]->toDateString())->toBe('2026-10-11')
        ->and($calendar->quarter(2019, 3)[0]->toDateString())->toBe($calendar->quarter(2019, 2)[1]->addDay()->toDateString())
        ->and($calendar->quarter(2019, 4)[1]->toDateString())->toBe('2027-07-07')
        ->and($calendar->fiscalYearOf(Carbon::parse('2026-07-07')->toImmutable()))->toBe(2018)
        ->and($calendar->fiscalYearOf(Carbon::parse('2026-07-08')->toImmutable()))->toBe(2019);
});

it('creates the four periods with the default deadlines', function (): void {
    $q1 = $this->s->period(1);
    expect($this->s->year->periods)->toHaveCount(4)
        ->and($q1->form_version)->toBe('fy2019')
        // Opens on Meskerem 1 (first day of the quarter's last month).
        ->and($q1->opens_on->toDateString())->toBe('2026-09-11')
        ->and($q1->submission_due_on->toDateString())->toBe('2026-10-20')
        ->and($q1->subcity_review_due_on->toDateString())->toBe('2026-10-25')
        ->and($q1->city_review_due_on->toDateString())->toBe('2026-10-30')
        ->and($q1->submissionDeadline()->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i:s'))->toBe('2026-10-20 23:59:59');

    $q2 = $this->s->period(2);
    expect($q2->opens_on->toDateString())->toBe('2026-12-10')
        ->and($q2->submission_due_on->toDateString())->toBe($q2->ends_on->addDays(10)->toDateString());

    expect(AuditLog::query()->where('event_type', 'service_monitoring.fiscal_year_created')->count())->toBe(1);
});

it('refuses a second fiscal year with the same number, through the command too', function (): void {
    $this->artisan('service-monitoring:create-fiscal-year', ['ec_year' => 2019])->assertFailed();
    $this->artisan('service-monitoring:create-fiscal-year', ['ec_year' => 2020])->expectsOutputToContain('2020')->assertSuccessful();
    expect(FiscalYear::query()->where('ec_year', 2020)->first()->periods)->toHaveCount(4);
});

it('lets the coordinator move a schedule, keeps the order of the dates, and audits it', function (): void {
    $coordinator = $this->s->coordinator();
    $q1 = $this->s->period(1);

    $this->actingAs($coordinator)->put(route('service-monitoring.periods.schedule', $q1), [
        'opens_on' => '2026-09-15', 'submission_due_on' => '2026-10-22', 'subcity_review_due_on' => '2026-10-27', 'city_review_due_on' => '2026-11-01',
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($q1->fresh()->submission_due_on->toDateString())->toBe('2026-10-22')
        ->and(AuditLog::query()->where('event_type', 'service_monitoring.schedule_updated')->exists())->toBeTrue();

    $this->actingAs($coordinator)->put(route('service-monitoring.periods.schedule', $q1), [
        'opens_on' => '2026-09-15', 'submission_due_on' => '2026-09-01', 'subcity_review_due_on' => '2026-10-27', 'city_review_due_on' => '2026-11-01',
    ])->assertSessionHasErrors('submission_due_on');

    $this->actingAs($this->s->head('wA1'))->put(route('service-monitoring.periods.schedule', $q1), [
        'opens_on' => '2026-09-15', 'submission_due_on' => '2026-10-25', 'subcity_review_due_on' => '2026-10-27', 'city_review_due_on' => '2026-11-01',
    ])->assertForbidden();
});

it('derives the period status from the schedule', function (): void {
    $q1 = $this->s->period(1);
    $this->s->clock('2026-09-10');
    expect($q1->effectiveStatus())->toBe(PeriodStatus::Scheduled);
    $this->s->clock('2026-10-20', '23:00');
    expect($q1->effectiveStatus())->toBe(PeriodStatus::Open);
    $this->s->clock('2026-10-21');
    expect($q1->effectiveStatus())->toBe(PeriodStatus::Closed);
});

it('keeps one active registry row per organization and a woreda under a sub-city', function (): void {
    $coordinator = $this->s->coordinator();

    $this->actingAs($coordinator)->post(route('service-monitoring.participants.store'), [
        'organization_id' => $this->s->org['wA1']->id, 'level' => 'woreda', 'parent_participant_id' => $this->s->p['subA']->id, 'active_from' => '2026-07-08',
    ])->assertSessionHasErrors('organization_id');

    $this->actingAs($coordinator)->put(route('service-monitoring.participants.update', $this->s->p['wA2']), [
        'level' => 'woreda', 'parent_participant_id' => $this->s->p['center']->id, 'active_from' => '2026-07-08',
    ])->assertSessionHasErrors('parent_participant_id');

    $this->actingAs($coordinator)->put(route('service-monitoring.participants.update', $this->s->p['center']), [
        'level' => 'center', 'parent_participant_id' => $this->s->p['subA']->id, 'active_from' => '2026-07-08',
    ])->assertSessionHasErrors('parent_participant_id');

    // Ending a row frees the organization for a new one.
    $this->actingAs($coordinator)->put(route('service-monitoring.participants.update', $this->s->p['wA2']), [
        'level' => 'woreda', 'parent_participant_id' => $this->s->p['subA']->id, 'active_from' => '2026-07-08', 'active_to' => '2026-09-30',
    ])->assertSessionHasNoErrors();
    $this->actingAs($coordinator)->post(route('service-monitoring.participants.store'), [
        'organization_id' => $this->s->org['wA2']->id, 'level' => 'woreda', 'parent_participant_id' => $this->s->p['subB']->id, 'active_from' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    expect(Participant::query()->where('organization_id', $this->s->org['wA2']->id)->count())->toBe(2);

    // A sub-city reviewer cannot manage the registry.
    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.participants.store'), [
        'organization_id' => $this->s->org['wB1']->id, 'level' => 'center', 'active_from' => '2026-07-08',
    ])->assertForbidden();
});

it('assigns only users who can answer clarifications as support officers', function (): void {
    $support = $this->s->user(ServiceMonitoringRoles::SUPPORT_OFFICER_PERMISSIONS, ['center']);
    $other = $this->s->head('center');

    $this->actingAs($this->s->coordinator())->put(route('service-monitoring.participants.support', $this->s->p['wA1']), ['user_ids' => [$support->id, $other->id]])
        ->assertSessionHasNoErrors();

    expect($this->s->p['wA1']->supportOfficers()->pluck('users.id')->all())->toBe([$support->id]);
});

it('suggests participants from the structure as a dry run and registers them with --apply', function (): void {
    $type = OrganizationType::query()->firstOrCreate(['code' => 'BUREAU'], ['name_en' => 'Bureau', 'level_order' => 2, 'category' => 'functional']);
    $health = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SM-HEALTH', 'name_en' => 'Health Bureau', 'status' => 'active']);

    $this->artisan('service-monitoring:suggest-participants')->expectsOutputToContain('Health Bureau')->assertSuccessful();
    expect(Participant::query()->where('organization_id', $health->id)->exists())->toBeFalse();

    $this->artisan('service-monitoring:suggest-participants', ['--apply' => true])->assertSuccessful();
    expect(Participant::query()->where('organization_id', $health->id)->first()?->level->value)->toBe('center');
});

it('registers the permissions in the catalog and the six roles in the matrix', function (): void {
    foreach (ServiceMonitoringRoles::ALL as $permission) {
        expect(PermissionCatalog::has($permission))->toBeTrue($permission)
            ->and(PermissionCatalog::category('service_monitoring'))->toBe('Service Delivery Monitoring');
    }
    expect(PermissionCatalog::problems())->toBe([])
        ->and(DefaultRoleMatrix::problems())->toBe([])
        ->and(DefaultRoleMatrix::permissionsFor(ServiceMonitoringRoles::COORDINATOR_ROLE))->not->toContain('service_monitoring.technology.verify')
        ->and(DefaultRoleMatrix::permissionsFor(ServiceMonitoringRoles::VERIFIER_ROLE))->toBe(['service_monitoring.view', 'service_monitoring.technology.verify', 'service_monitoring.reports.view'])
        // Named Section I data is withheld from the city-wide operational admins.
        ->and(DefaultRoleMatrix::permissionsFor('City Admin'))->not->toContain('service_monitoring.accountability.view_named')
        ->and(DefaultRoleMatrix::permissionsFor('Public Service Bureau Admin'))->not->toContain('service_monitoring.accountability.export_named')
        ->and(DefaultRoleMatrix::permissionsFor('City Admin'))->toContain('service_monitoring.review')
        ->and(DefaultRoleMatrix::permissionsFor('Super Admin'))->toContain('service_monitoring.accountability.export_named');
});

it('answers 404 on every module route while the feature flag is off', function (): void {
    config(['features.service_monitoring' => false]);
    // The rate limiter is per user across routes; this test calls every route once.
    $this->withoutMiddleware(ThrottleRequests::class);
    $user = $this->s->coordinator();

    $routes = collect(Route::getRoutes()->getRoutesByName())->filter(fn ($r, string $name): bool => str_starts_with($name, 'service-monitoring.'));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $name => $route) {
        $uri = preg_replace('/\{[^}]+\}/', (string) Str::uuid7(), $route->uri());
        $method = $route->methods()[0];
        $this->actingAs($user)->call($method === 'GET' || $method === 'HEAD' ? 'GET' : $method, '/'.$uri)->assertNotFound();
    }
});
