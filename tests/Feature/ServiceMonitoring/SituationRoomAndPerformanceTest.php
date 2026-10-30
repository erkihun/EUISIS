<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationRoomService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Modules\ServiceMonitoring\Application\Actions\RegisterParticipant;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\InstitutionBuilding;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ServiceTotals;
use Modules\ServiceMonitoring\Domain\Models\StandardPerformance;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Tests\Support\ServiceMonitoringScenario;

/*
 * The Situation Room's service_monitoring category (D26), and query budgets
 * for the dashboard and the city consolidation at the scale of the city
 * (≈130 participants × 4 quarters).
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('shows the service_monitoring category with unnamed panels to an authorized user only', function (): void {
    $this->s->accepted('wA1');
    $viewer = $this->s->user([SituationPermissions::VIEW, SituationPermissions::OPERATIONS], [], true);
    $other = $this->s->user([SituationPermissions::VIEW, SituationPermissions::CAFETERIA], [], true);

    expect(SituationCategory::ServiceMonitoring->visibleTo($viewer))->toBeTrue()
        ->and(SituationCategory::ServiceMonitoring->visibleTo($other))->toBeFalse()
        ->and(SituationCategory::ServiceDelivery->value)->toBe('service_delivery');

    $room = app(SituationRoomService::class);
    $context = $room->context($viewer, Request::create('/', 'GET', ['period' => 'year']));
    $result = $room->run($room->analytics(SituationCategory::ServiceMonitoring), $context);
    expect(array_column($result['panels'], 'key'))->toBe(['sm_kpis', 'sm_compliance', 'sm_accountability', 'sm_standards'])
        ->and($result['has_errors'])->toBeFalse()
        ->and(json_encode($result))->not->toContain('Abebe');

    config(['features.service_monitoring' => false]);
    expect(SituationCategory::ServiceMonitoring->visibleTo($viewer))->toBeFalse();
});

/** 130 participants with accepted reports in all four quarters, written in bulk. */
function smSeedCity(ServiceMonitoringScenario $s): void
{
    $register = app(RegisterParticipant::class);
    $type = $s->org['center']->organization_type_id;
    for ($i = 0; $i < 124; $i++) {
        $org = Organization::query()->create(['organization_type_id' => $type, 'code' => 'SM-PERF-'.$i, 'name_en' => "Bureau {$i}", 'status' => 'active']);
        $register->execute(['organization_id' => $org->id, 'level' => 'center', 'active_from' => '2026-07-08'], null);
    }
    foreach (Participant::query()->get() as $participant) {
        foreach ($s->periods as $quarter => $period) {
            $submission = Submission::query()->create([
                'period_id' => $period->id, 'participant_id' => $participant->id, 'organization_id' => $participant->organization_id, 'level' => $participant->level,
                'form_type' => $participant->formType(), 'form_version' => 'fy2019', 'revision_no' => 1, 'is_current' => true, 'status' => 'accepted',
                'section_1_nothing' => true, 'section_3_nothing' => true, 'submitted_at' => now(), 'accepted_at' => now(),
            ]);
            InstitutionBuilding::query()->create(['submission_id' => $submission->id, ...array_fill_keys(FormDefinition::section2Keys(), $quarter)]);
            ServiceTotals::query()->create(['submission_id' => $submission->id, 'main_total' => 10, 'sub_total' => 20]);
            StandardPerformance::query()->create(['submission_id' => $submission->id, 'main_above' => 2, 'main_at' => 8, 'main_below' => 0, 'sub_above' => 5, 'sub_at' => 15, 'sub_below' => 0]);
        }
    }
}

it('consolidates the city for the year in a bounded number of queries', function (): void {
    smSeedCity($this->s);
    $this->s->at(4);
    app(ConsolidationService::class)->forget();

    DB::enableQueryLog();
    $report = app(ConsolidationService::class)->report($this->s->year, ReportRange::Annual, 4, null, false);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($report['totals']['all']['count'])->toBe(130)
        // Flow: 1+2+3+4 per participant; stock: the Q4 figure.
        ->and($report['totals']['all']['section2']['awareness_trainings'])->toBe(130 * 10)
        ->and($report['totals']['all']['section2']['led_count'])->toBe(130 * 4)
        ->and($queries)->toBeLessThanOrEqual(20);
});

it('renders the dashboard and the city consolidation page within a query budget', function (): void {
    smSeedCity($this->s);
    $this->s->at(4);
    $coordinator = $this->s->coordinator();
    $this->actingAs($coordinator)->get(route('service-monitoring.dashboard'))->assertOk();

    DB::enableQueryLog();
    $this->actingAs($coordinator)->get(route('service-monitoring.consolidation.city', ['range' => 'annual', 'quarter' => 4]))->assertOk();
    $city = count(DB::getQueryLog());
    DB::flushQueryLog();
    // The deferred dashboard figures arrive in a partial reload.
    $this->actingAs($coordinator)->get(route('service-monitoring.dashboard'), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'ServiceMonitoring/Dashboard', 'X-Inertia-Partial-Data' => 'compliance,overdue,clarifications',
    ])->assertOk()->assertJsonPath('props.compliance.participants', 130);
    $dashboard = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($city)->toBeLessThanOrEqual(80)->and($dashboard)->toBeLessThanOrEqual(60);
});
