<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\DailyActivityStatus;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\PlanStatus;
use App\Models\DailyActivityItem;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\User;
use App\Services\DailyActivity\DailyActivitySettings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * KPI actuals (docs/epms-calculation-rules.md §5).
 *
 * Every actual belongs to ONE subject (agreement item or plan target), ONE
 * period and ONE source; the (subject, period, source) key is unique, so the
 * same number can never be entered twice. Only the item's configured data
 * source counts toward its score — a manual entry can never be added on top
 * of daily-activity or system data for the same KPI.
 *
 *   MANUAL           entered by the manager/HR (policy switch), verified by
 *                    someone else
 *   DAILY_ACTIVITY   Σ quantity of the employee's approved daily-activity
 *                    items linked to the KPI item — evidence of progress,
 *                    converted into an actual; never a score by itself
 *   SYSTEM_TRANSACTION  measured by SystemKpiSourceRegistry; not overwritable
 */
final class KpiActualService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
        private readonly SystemKpiSourceRegistry $systemSources,
        private readonly DailyActivitySettings $dailyActivity,
    ) {}

    /** @param array<string, mixed> $data period_start, period_end, actual_value|actual_numerator+actual_denominator|milestone_key, comment */
    public function recordForItem(EmployeePerformanceItem $item, array $data, User $actor): KpiActual
    {
        $agreement = $item->agreement;
        $this->access->authorize($actor->can('kpi_actuals.enter') && $this->access->canManageAgreement($actor, $agreement));

        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::UnderReview, AgreementStatus::Closed], true)) {
            throw ValidationException::withMessages(['agreement' => __('performance.errors.agreement_not_active')]);
        }
        $this->assertManualAllowed($item->data_source_type);
        [$from, $to] = $this->period($data, $agreement->effective_from, $agreement->effective_to);
        $this->assertMilestone($item->kpi->milestones ?? [], $data['milestone_key'] ?? null, $actor);

        return $this->store([
            'kpi_id' => $item->kpi_id,
            'employee_performance_item_id' => $item->getKey(),
            'subject_key' => 'item:'.$item->getKey(),
            'agreement_id' => $agreement->getKey(),
            'performance_plan_id' => $agreement->performance_plan_id,
            'employee_id' => $agreement->employee_id,
            'organization_id' => $agreement->organization_id,
            'organization_unit_id' => $agreement->organization_unit_id,
        ], $from, $to, KpiDataSource::Manual, 'manual', $data, $actor);
    }

    /** Own measurement for a unit/organization target (e.g. a verified survey figure). */
    public function recordForTarget(KpiTarget $target, array $data, User $actor): KpiActual
    {
        $plan = $target->plan;
        $this->access->authorize($this->access->inScope($actor, 'kpi_actuals.enter', $plan->organization_id));
        if ($plan->status !== PlanStatus::Published) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.plan_not_published')]);
        }
        $this->assertManualAllowed($target->kpi->data_source_type);
        [$from, $to] = $this->period($data, $target->period_start, $target->period_end);
        $this->assertMilestone($target->kpi->milestones ?? [], $data['milestone_key'] ?? null, $actor);

        return $this->store([
            'kpi_id' => $target->kpi_id,
            'target_id' => $target->getKey(),
            'subject_key' => 'target:'.$target->getKey(),
            'performance_plan_id' => $plan->getKey(),
            'organization_id' => $plan->organization_id,
            'organization_unit_id' => $plan->organization_unit_id,
        ], $from, $to, KpiDataSource::Manual, 'manual', $data, $actor);
    }

    public function verify(KpiActual $actual, User $actor): KpiActual
    {
        $allowed = $actor->can('kpi_actuals.verify') && ($actual->agreement_id !== null
            ? $this->access->canManageAgreement($actor, $actual->agreement)
            : $this->access->inScope($actor, 'kpi_actuals.verify', $actual->organization_id));
        $this->access->authorize($allowed);
        $this->access->assertSeparated($actor, $actual->entered_by, 'verify');

        $actual->forceFill(['verified' => true, 'verified_by' => $actor->getKey(), 'verified_at' => now()])->save();
        $this->audit->record(AuditEventType::KpiActualVerified, $actor, $actual, ['actual' => $actual->getKey()]);

        return $actual;
    }

    /**
     * Convert the employee's approved daily activity for this KPI item and
     * period into one DAILY_ACTIVITY actual (Σ quantity). Re-running replaces
     * the same row — it never adds a second one.
     */
    public function syncDailyActivity(EmployeePerformanceItem $item, Carbon $from, Carbon $to): ?KpiActual
    {
        if ($item->data_source_type !== KpiDataSource::DailyActivity) {
            return null;
        }

        $agreement = $item->agreement;
        $statuses = $this->dailyActivity->managerReviewRequired()
            ? [DailyActivityStatus::Approved->value]
            : DailyActivityStatus::submittedValues();

        $query = DailyActivityItem::query()
            ->where('employee_performance_item_id', $item->getKey())
            ->whereHas('log', fn ($log) => $log->where('employee_id', $agreement->employee_id)
                ->whereIn('status', $statuses)
                ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()]));

        $count = (clone $query)->count();
        $quantity = (string) (clone $query)->sum('quantity');

        return KpiActual::query()->updateOrCreate(
            ['subject_key' => 'item:'.$item->getKey(), 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'source_key' => 'daily_activity'],
            [
                'kpi_id' => $item->kpi_id,
                'employee_performance_item_id' => $item->getKey(),
                'agreement_id' => $agreement->getKey(),
                'performance_plan_id' => $agreement->performance_plan_id,
                'employee_id' => $agreement->employee_id,
                'organization_id' => $agreement->organization_id,
                'organization_unit_id' => $agreement->organization_unit_id,
                'actual_value' => $quantity,
                'source_type' => KpiDataSource::DailyActivity,
                'source_reference_type' => 'daily_activity_items',
                'source_reference_id' => (string) $count,
            ],
        );
    }

    /** Measure a SYSTEM_TRANSACTION item or target from its registered source. */
    public function syncSystem(EmployeePerformanceItem|KpiTarget $subject, Carbon $from, Carbon $to): ?KpiActual
    {
        $kpi = $subject->kpi;
        if ($kpi->data_source_type !== KpiDataSource::SystemTransaction || ! SystemKpiSourceRegistry::has($kpi->system_source_key)) {
            return null;
        }

        $isItem = $subject instanceof EmployeePerformanceItem;
        $organizationId = $isItem ? $subject->agreement->organization_id : $subject->plan->organization_id;
        $employeeId = $isItem ? $subject->agreement->employee_id : null;
        $measure = $this->systemSources->measure($kpi->system_source_key, $organizationId, $employeeId, $from, $to);

        return KpiActual::query()->updateOrCreate(
            ['subject_key' => ($isItem ? 'item:' : 'target:').$subject->getKey(), 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'source_key' => 'system:'.$kpi->system_source_key],
            [
                'kpi_id' => $kpi->getKey(),
                'employee_performance_item_id' => $isItem ? $subject->getKey() : null,
                'target_id' => $isItem ? null : $subject->getKey(),
                'agreement_id' => $isItem ? $subject->agreement_id : null,
                'performance_plan_id' => $isItem ? $subject->agreement->performance_plan_id : $subject->performance_plan_id,
                'employee_id' => $employeeId,
                'organization_id' => $organizationId,
                'organization_unit_id' => $isItem ? $subject->agreement->organization_unit_id : $subject->plan->organization_unit_id,
                'actual_value' => $measure['value'],
                'actual_numerator' => $measure['numerator'],
                'actual_denominator' => $measure['denominator'],
                'source_type' => KpiDataSource::SystemTransaction,
                'source_reference_type' => $measure['reference'],
                // System data is verified by construction.
                'verified' => true,
                'verified_at' => now(),
            ],
        );
    }

    private function assertManualAllowed(KpiDataSource $source): void
    {
        if (! in_array($source, [KpiDataSource::Manual, KpiDataSource::DocumentEvidence, KpiDataSource::Survey], true)) {
            throw ValidationException::withMessages(['actual_value' => __('performance.errors.source_not_manual', ['source' => $source->value])]);
        }
        if (! $this->settings->allowManualActual()) {
            throw ValidationException::withMessages(['actual_value' => __('performance.errors.manual_disabled')]);
        }
    }

    /** @param array<int, array<string, mixed>> $milestones */
    private function assertMilestone(array $milestones, ?string $key, User $actor): void
    {
        if ($key === null) {
            return;
        }
        $milestone = collect($milestones)->firstWhere('key', $key);
        if ($milestone === null) {
            throw ValidationException::withMessages(['milestone_key' => __('performance.errors.unknown_milestone')]);
        }
        if (($milestone['requires_verification'] ?? false) && ! $actor->can('kpi_actuals.verify')) {
            throw ValidationException::withMessages(['milestone_key' => __('performance.errors.milestone_needs_verifier')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(array $data, mixed $min, mixed $max): array
    {
        $from = Carbon::parse($data['period_start']);
        $to = Carbon::parse($data['period_end']);
        if ($to->lt($from) || $from->lt(Carbon::parse($min)) || $to->gt(Carbon::parse($max))) {
            throw ValidationException::withMessages(['period_start' => __('performance.errors.period_outside_agreement')]);
        }

        return [$from, $to];
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $data
     */
    private function store(array $subject, Carbon $from, Carbon $to, KpiDataSource $source, string $sourceKey, array $data, User $actor): KpiActual
    {
        $key = ['subject_key' => $subject['subject_key'], 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'source_key' => $sourceKey];
        $existing = KpiActual::query()->where($key)->first();

        // A verified figure is changed only by a verifier (and becomes unverified again).
        if ($existing?->verified && ! $actor->can('kpi_actuals.verify')) {
            throw ValidationException::withMessages(['actual_value' => __('performance.errors.actual_verified')]);
        }

        $values = [
            ...$subject,
            'actual_value' => $data['actual_value'] ?? null,
            'actual_numerator' => $data['actual_numerator'] ?? null,
            'actual_denominator' => $data['actual_denominator'] ?? null,
            'milestone_key' => $data['milestone_key'] ?? null,
            'comment' => $data['comment'] ?? null,
            'source_type' => $source,
            'entered_by' => $actor->getKey(),
        ];

        $actual = $existing ?? new KpiActual($key);
        $old = $existing?->only(['actual_value', 'actual_numerator', 'actual_denominator', 'milestone_key']);
        $actual->fill($values);
        $actual->forceFill(['verified' => false, 'verified_by' => null, 'verified_at' => null])->save();

        $this->audit->record(AuditEventType::KpiActualRecorded, $actor, $actual, [
            'subject' => $subject['subject_key'], 'period' => $key['period_start'].'..'.$key['period_end'],
            'actual_value' => $actual->actual_value, 'numerator' => $actual->actual_numerator, 'denominator' => $actual->actual_denominator, 'milestone' => $actual->milestone_key,
        ], $old);

        return $actual;
    }
}
