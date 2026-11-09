<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Actions\CertifySubCityConsolidation;
use Modules\ServiceMonitoring\Application\Actions\LockPeriod;
use Modules\ServiceMonitoring\Application\Actions\RecordNonSubmission;
use Modules\ServiceMonitoring\Application\Actions\ReopenPeriod;
use Modules\ServiceMonitoring\Application\Actions\ReturnSubmission;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\ConsolidationSnapshot;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Review and consolidation (phase 4): flow vs stock across the ranges (D3,
 * D4), woreda → sub-city → city sums, certification (D8), the period lock
 * and its immutable snapshot (D24), reopening (D9).
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function smReport(ServiceMonitoringScenario $s, ReportRange $range, int $quarter, ?array $ids = null): array
{
    app(ConsolidationService::class)->forget();

    return app(ConsolidationService::class)->report($s->year, $range, $quarter, $ids, false);
}

it('sums flow figures and takes stock figures from the latest quarter', function (): void {
    // Q1: 2 trainings, 10 LEDs; Q2: 3 trainings, 12 LEDs; Q3 not reported; Q4: 1 training, 15 LEDs.
    $this->s->accepted('wA1', 1, ['awareness_trainings' => 2, 'led_count' => 10, 'daycare_count' => 1]);
    $this->s->accepted('wA1', 2, ['awareness_trainings' => 3, 'led_count' => 12, 'daycare_count' => 0]);
    $this->s->accepted('wA1', 4, ['awareness_trainings' => 1, 'led_count' => 15, 'daycare_count' => 2], main: 11, sub: 22);

    $row = fn (ReportRange $range, int $q): array => smReport($this->s, $range, $q, [$this->s->p['wA1']->id])['rows'][0];
    expect($row(ReportRange::Quarter, 2)['section2'])->awareness_trainings->toBe(3)->led_count->toBe(12)
        ->and($row(ReportRange::SixMonths, 2)['section2'])->awareness_trainings->toBe(5)->led_count->toBe(12)->daycare_count->toBe(1)
        // Q3 has no accepted report: the stock figures stay those of Q2.
        ->and($row(ReportRange::NineMonths, 3)['section2'])->awareness_trainings->toBe(5)->led_count->toBe(12)
        ->and($row(ReportRange::Annual, 4)['section2'])->awareness_trainings->toBe(6)->led_count->toBe(15)->daycare_count->toBe(3)
        ->and($row(ReportRange::Annual, 4)['totals'])->toBe(['main_total' => 11, 'sub_total' => 22])
        ->and($row(ReportRange::Annual, 4)['quarters_reported'])->toBe([1, 2, 4])
        // Section IV is assessed per quarter: summed, with the latest quarter alongside.
        ->and($row(ReportRange::Annual, 4)['section4']['main_above'])->toBe(6)
        ->and($row(ReportRange::Annual, 4)['section4_latest']['main_at'])->toBe(9);
});

it('consolidates woreda → sub-city → city as the sum of the accepted current revisions', function (): void {
    $this->s->accepted('wA1', 1, ['led_count' => 3]);
    $this->s->accepted('wA2', 1, ['led_count' => 4]);
    $this->s->accepted('subA', 1, ['led_count' => 5]);
    $this->s->accepted('wB1', 1, ['led_count' => 6]);
    $this->s->accepted('center', 1, ['led_count' => 7]);
    // A draft and a submitted report never count.
    $this->s->start('subB');

    $subA = smReport($this->s, ReportRange::Quarter, 1, app(ConsolidationService::class)->subCityParticipantIds($this->s->p['subA']));
    expect($subA['totals']['all']['section2']['led_count'])->toBe(12)
        ->and($subA['totals']['woreda']['section2']['led_count'])->toBe(7)
        ->and($subA['totals']['sub_city']['section2']['led_count'])->toBe(5);

    $city = smReport($this->s, ReportRange::Quarter, 1);
    expect($city['totals']['all']['section2']['led_count'])->toBe(25)
        ->and($city['totals']['all']['count'])->toBe(5)
        ->and(collect($city['rows'])->pluck('level')->unique()->values()->all())->toBe(['center', 'sub_city', 'woreda']);
});

it('certifies a sub-city only when its woredas are accepted or recorded as not submitted', function (): void {
    $period = $this->s->period(1);
    $reviewer = $this->s->reviewer('subA');
    $this->s->accepted('subA', 1);
    $this->s->accepted('wA1', 1);

    expect(fn () => app(CertifySubCityConsolidation::class)->execute($period, $this->s->p['subA'], $reviewer))->toThrow(ValidationException::class);

    // wA2 never submits; after the deadline the sub-city records it.
    $this->s->clock('2026-10-22');
    $this->actingAs($reviewer)->post(route('service-monitoring.consolidation.not-submitted', [$period, $this->s->p['wA2']]), ['reason' => 'No report and no reply to three calls.'])->assertSessionHasNoErrors();
    $this->actingAs($this->s->reviewer('subB'))->post(route('service-monitoring.consolidation.certify', [$period, $this->s->p['subA']]))->assertForbidden();
    $this->actingAs($reviewer)->post(route('service-monitoring.consolidation.certify', [$period, $this->s->p['subA']]))->assertSessionHasNoErrors();

    $snapshot = ConsolidationSnapshot::query()->sole();
    expect($snapshot->kind->value)->toBe('subcity_certification')
        ->and($snapshot->isIntact())->toBeTrue()
        ->and(array_keys($snapshot->payload['participants']))->toEqualCanonicalizing([$this->s->p['subA']->id, $this->s->p['wA1']->id]);
    expect(fn () => $snapshot->update(['checksum' => 'x']))->toThrow(LogicException::class);
});

it('records a returned report never resubmitted as not submitted once the deadline passed, so the sub-city can certify', function (): void {
    $period = $this->s->period(1);
    $reviewer = $this->s->reviewer('subA');
    $this->s->accepted('subA', 1);
    $this->s->accepted('wA1', 1);
    $returned = $this->s->submit($this->s->complete($this->s->start('wA2', 1)));
    app(ReturnSubmission::class)->execute($returned, $reviewer, 'The waiting-area figures do not match the visit.');
    $url = route('service-monitoring.consolidation.not-submitted', [$period, $this->s->p['wA2']]);

    // Before the deadline the woreda may still resubmit.
    $this->actingAs($reviewer)->post($url, ['reason' => 'Returned and not resubmitted.'])->assertSessionHasErrors('participant_id');

    $this->s->clock('2026-10-22');
    $this->actingAs($reviewer)->get(route('service-monitoring.consolidation.sub-city', ['participant_id' => $this->s->p['subA']->id, 'quarter' => 1, 'range' => 'quarter']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('woredas.1.compliance.recordable', true));
    $this->actingAs($reviewer)->post($url, ['reason' => 'Returned and not resubmitted.'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('service-monitoring.consolidation.certify', [$period, $this->s->p['subA']]))->assertSessionHasNoErrors();
    expect(ConsolidationSnapshot::query()->where('kind', 'subcity_certification')->exists())->toBeTrue();
});

it('locks a period only when every sub-city certified, then reads it from the snapshot whatever the data does', function (): void {
    $period = $this->s->period(1);
    $coordinator = $this->s->coordinator();
    foreach (['center', 'subA', 'wA1', 'wA2', 'subB', 'wB1'] as $key) {
        $this->s->accepted($key, 1, ['led_count' => 2]);
    }
    app(CertifySubCityConsolidation::class)->execute($period, $this->s->p['subA'], $this->s->reviewer('subA'));
    expect(fn () => app(LockPeriod::class)->execute($period->fresh(), $coordinator))->toThrow(ValidationException::class);
    app(CertifySubCityConsolidation::class)->execute($period->fresh(), $this->s->p['subB'], $this->s->reviewer('subB'));

    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.periods.lock', $period))->assertForbidden();
    $this->actingAs($coordinator)->post(route('service-monitoring.periods.lock', $period))->assertSessionHasNoErrors();
    expect($period->fresh()->isLocked())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'service_monitoring.period_locked')->exists())->toBeTrue();

    $before = smReport($this->s, ReportRange::Quarter, 1);
    expect($before['from_snapshot'])->toBeTrue()->and($before['totals']['all']['section2']['led_count'])->toBe(12);

    // Someone changes the data underneath: the locked report does not move.
    DB::table('service_monitoring_institution_building')->update(['led_count' => 99]);
    expect(smReport($this->s, ReportRange::Quarter, 1)['totals']['all']['section2']['led_count'])->toBe(12);

    // Every write in the period is refused.
    $submission = Submission::query()->where('participant_id', $this->s->p['wA1']->id)->sole();
    $this->actingAs($this->s->head('wA1'))->post(route('service-monitoring.corrections.store', $submission), ['reason' => 'Please let us fix the LED count of this quarter.'])->assertForbidden();
    expect(fn () => app(RecordNonSubmission::class)->execute($period->fresh(), $this->s->p['wA1'], 'Trying to change a locked period.', $coordinator))->toThrow(ValidationException::class);
});

it('neither offers nor allows locking a period that has not opened yet', function (): void {
    $period = $this->s->period(2);
    $coordinator = $this->s->coordinator();
    $this->s->clock($period->opens_on->subDay()->toDateString());

    expect($coordinator->can('lock', $period))->toBeFalse()
        ->and(fn () => app(LockPeriod::class)->execute($period->fresh(), $coordinator))->toThrow(ValidationException::class);
    $this->actingAs($coordinator)->post(route('service-monitoring.periods.lock', $period))->assertForbidden();
    expect($period->fresh()->isLocked())->toBeFalse();
});

it('reopens a locked period with a reason, audits it and reads live figures again', function (): void {
    $period = $this->s->period(1);
    $coordinator = $this->s->coordinator();
    $this->s->accepted('center', 1, ['led_count' => 2]);
    foreach (['subA', 'subB'] as $key) {
        $this->s->accepted($key, 1, ['led_count' => 1]);
    }
    foreach (['wA1', 'wA2', 'wB1'] as $key) {
        $this->s->accepted($key, 1, ['led_count' => 1]);
    }
    app(CertifySubCityConsolidation::class)->execute($period, $this->s->p['subA'], $this->s->reviewer('subA'));
    app(CertifySubCityConsolidation::class)->execute($period->fresh(), $this->s->p['subB'], $this->s->reviewer('subB'));
    app(LockPeriod::class)->execute($period->fresh(), $coordinator);

    $reviewer = $this->s->reviewer('subA');
    $this->actingAs($reviewer)->post(route('service-monitoring.periods.reopen', $period), ['reason' => 'Wrong figures from a woreda.'])->assertForbidden();
    $this->actingAs($coordinator)->post(route('service-monitoring.periods.reopen', $period), ['reason' => 'short'])->assertSessionHasErrors('reason');
    app(ReopenPeriod::class)->execute($period->fresh(), $coordinator, 'A woreda reported the LED count of the wrong quarter.');

    expect($period->fresh()->isLocked())->toBeFalse()
        ->and($period->fresh()->reopened_reason)->toBe('A woreda reported the LED count of the wrong quarter.')
        ->and(AuditLog::query()->where('event_type', 'service_monitoring.period_reopened')->exists())->toBeTrue()
        ->and(smReport($this->s, ReportRange::Quarter, 1)['from_snapshot'])->toBeFalse();
});

it('keeps the form definition the single source of the field types', function (): void {
    foreach (FormDefinition::section2Flow() as $field) {
        expect(FormDefinition::aggregationOf($field)->value)->toBe('flow');
    }
    expect(FormDefinition::section2Stock())->toContain('waiting_area_count', 'waiting_area_capacity', 'suggestion_box_count', 'led_count', 'signage_count', 'employees_excl_drivers', 'chest_badges_excl_drivers', 'desk_badges')
        ->and(FormDefinition::section2Flow())->toBe(['daycare_count', 'cafeteria_count', 'consumer_shop_count', 'awareness_trainings', 'awareness_participants']);
});
