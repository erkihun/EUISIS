<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Fiscal years and periods for pickers, and "the current period" of the dashboards. */
class PeriodDirectory
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    /**
     * The period being worked on today: the one whose window (opening to city
     * review) contains today; else the latest opened; else the next one.
     */
    public function current(): ?ReportingPeriod
    {
        $today = $this->calendar->today();

        return ReportingPeriod::query()->with('fiscalYear')->where('opens_on', '<=', $today)->where('city_review_due_on', '>=', $today)->orderByDesc('opens_on')->first()
            ?? ReportingPeriod::query()->with('fiscalYear')->where('opens_on', '<=', $today)->orderByDesc('opens_on')->first()
            ?? ReportingPeriod::query()->with('fiscalYear')->orderBy('opens_on')->first();
    }

    /** @return list<array<string, mixed>> */
    public function years(): array
    {
        return FiscalYear::query()->with('periods')->orderByDesc('ec_year')->get()->map(fn (FiscalYear $year): array => [
            'id' => $year->id,
            'ec_year' => $year->ec_year,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'status' => $year->status->value,
            'periods' => $year->periods->map(fn (ReportingPeriod $p): array => $this->period($p))->values()->all(),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function period(ReportingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'fiscal_year_id' => $period->fiscal_year_id,
            'quarter' => $period->quarter,
            'label' => $period->label(),
            'form_version' => $period->form_version,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'opens_on' => $period->opens_on->toDateString(),
            'submission_due_on' => $period->submission_due_on->toDateString(),
            'subcity_review_due_on' => $period->subcity_review_due_on->toDateString(),
            'city_review_due_on' => $period->city_review_due_on->toDateString(),
            'deadline_at' => $period->submissionDeadline()->toIso8601String(),
            'status' => $period->effectiveStatus()->value,
            'locked_at' => $period->locked_at?->toIso8601String(),
            'reopened_reason' => $period->reopened_reason,
        ];
    }
}
