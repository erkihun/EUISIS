<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Contracts\EmployeeLeaveProvider;
use App\Enums\DailyActivityDayStatus;
use App\Enums\DailyActivityStatus;
use App\Enums\EmployeeStatus;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calculates the state of every day for one or many employees.
 *
 * Missing activity is DERIVED, never stored:
 *
 *   employed on the date
 *   + assigned on the date
 *   + configured working weekday
 *   - public holiday
 *   - full-day approved leave
 *   - days before the tracking start date
 *   - days that already have a submitted log
 *
 * Evaluation order matters and is fixed here in one place so the employee
 * calendar, the manager dashboard and every report agree on each day.
 */
class DailyActivityCalendarService
{
    public function __construct(
        private readonly DailyActivitySettings $settings,
        private readonly WorkCalendarService $calendar,
        private readonly EmployeeWorkContextResolver $context,
        private readonly EmployeeLeaveProvider $leave,
    ) {}

    /**
     * Day-by-day states for one employee.
     *
     * @return array<int, array<string, mixed>>
     */
    public function days(Employee $employee, Carbon $from, Carbon $to): array
    {
        $context = $this->load(collect([$employee]), $from, $to);
        $days = [];

        foreach ($this->period($from, $to) as $date) {
            $day = $this->evaluate($employee, $date, $context);
            $holiday = $context['holidays'][$date] ?? null;

            $days[] = [
                'date' => $date,
                'status' => $day['status']->value,
                'is_required' => $day['status']->isRequiredDay(),
                'log_id' => $day['log']?->id,
                'is_late' => (bool) ($day['log']?->is_late ?? false),
                'items_count' => (int) ($day['log']?->items_count ?? 0),
                'holiday_name_en' => $holiday['name_en'] ?? null,
                'holiday_name_am' => $holiday['name_am'] ?? null,
                'can_register' => $this->isRegistrableDay($day['status'], $date),
            ];
        }

        return $days;
    }

    /** State of one day for one employee. */
    public function dayStatus(Employee $employee, Carbon $date): DailyActivityDayStatus
    {
        $context = $this->load(collect([$employee]), $date, $date);

        return $this->evaluate($employee, $date->toDateString(), $context)['status'];
    }

    /**
     * Per-employee summaries over a range.
     *
     * $inScope restricts counting to days whose assignment satisfies it, so a
     * unit report counts an employee only for the days they belonged to that
     * unit. Days outside scope are ignored entirely.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  (callable(EmployeeAssignment): bool)|null  $inScope
     * @return array<string, array<string, mixed>>
     */
    public function summaries(Collection $employees, Carbon $from, Carbon $to, ?callable $inScope = null): array
    {
        $context = $this->load($employees, $from, $to);
        $dates = $this->period($from, $to);
        $today = $this->settings->today()->toDateString();
        $summaries = [];

        foreach ($employees as $employee) {
            $summary = [
                'required' => 0,
                'submitted' => 0,
                'approved' => 0,
                'returned' => 0,
                'draft' => 0,
                'missing' => 0,
                'leave' => 0,
                'holiday' => 0,
                'weekend' => 0,
                'late' => 0,
                'items' => 0,
                'missing_dates' => [],
                'last_assignment' => null,
            ];

            foreach ($dates as $date) {
                $day = $this->evaluate($employee, $date, $context);

                if ($day['assignment'] === null || ($inScope !== null && ! $inScope($day['assignment']))) {
                    continue;
                }

                $summary['last_assignment'] = $day['assignment'];
                $status = $day['status'];

                match ($status) {
                    DailyActivityDayStatus::Leave => $summary['leave']++,
                    DailyActivityDayStatus::PublicHoliday => $summary['holiday']++,
                    DailyActivityDayStatus::Weekend => $summary['weekend']++,
                    default => null,
                };

                if ($day['log'] !== null) {
                    $summary['items'] += (int) $day['log']->items_count;
                    if ($day['log']->is_late && $day['log']->status->countsAsSubmitted()) {
                        $summary['late']++;
                    }
                }

                if (! $status->isRequiredDay()) {
                    continue;
                }

                $summary['required']++;

                if ($this->isMissing($status, $date, $today)) {
                    $summary['missing']++;
                    $summary['missing_dates'][] = $date;
                }

                // Approved days are submitted days too.
                if ($status === DailyActivityDayStatus::Submitted || $status === DailyActivityDayStatus::Approved) {
                    $summary['submitted']++;
                }
                if ($status === DailyActivityDayStatus::Approved) {
                    $summary['approved']++;
                }
                if ($status === DailyActivityDayStatus::Returned) {
                    $summary['returned']++;
                }
                if ($status === DailyActivityDayStatus::Draft) {
                    $summary['draft']++;
                }
            }

            $summaries[$employee->id] = $summary;
        }

        return $summaries;
    }

    /**
     * Every in-scope employee-day with its calculated status, for reports
     * that group days differently (by date, by unit, missing only).
     *
     * @param  Collection<int, Employee>  $employees
     * @param  (callable(EmployeeAssignment): bool)|null  $inScope
     * @return array<int, array{employee: Employee, date: string, status: DailyActivityDayStatus, log: ?DailyActivityLog, assignment: EmployeeAssignment, missing: bool}>
     */
    public function rows(Collection $employees, Carbon $from, Carbon $to, ?callable $inScope = null): array
    {
        $context = $this->load($employees, $from, $to);
        $today = $context['today'];
        $rows = [];

        foreach ($employees as $employee) {
            foreach ($this->period($from, $to) as $date) {
                $day = $this->evaluate($employee, $date, $context);

                if ($day['assignment'] === null || ($inScope !== null && ! $inScope($day['assignment']))) {
                    continue;
                }

                $rows[] = [
                    'employee' => $employee,
                    'date' => $date,
                    'status' => $day['status'],
                    'log' => $day['log'],
                    'assignment' => $day['assignment'],
                    'missing' => $day['status']->isRequiredDay() && $this->isMissing($day['status'], $date, $today),
                ];
            }
        }

        return $rows;
    }

    /**
     * A required day counts as missing once it is past and nothing was
     * submitted. A draft left past its day is incomplete work, so it is
     * missing too; a returned log is tracked separately as "returned".
     */
    public function isMissing(DailyActivityDayStatus $status, string $date, string $today): bool
    {
        return $status === DailyActivityDayStatus::Missing
            || ($status === DailyActivityDayStatus::Draft && $date < $today);
    }

    /**
     * May the employee open this date at all (to enter or to read)?
     * Backdating and deadline rules are enforced by DailyActivityService;
     * this only rules out days that can never carry activity.
     */
    public function isRegistrableDay(DailyActivityDayStatus $status, string $date): bool
    {
        return ! in_array($status, [
            DailyActivityDayStatus::Future,
            DailyActivityDayStatus::Weekend,
            DailyActivityDayStatus::PublicHoliday,
            DailyActivityDayStatus::Leave,
            DailyActivityDayStatus::NotEmployed,
            DailyActivityDayStatus::NotAssigned,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{status: DailyActivityDayStatus, log: ?DailyActivityLog, assignment: ?EmployeeAssignment}
     */
    private function evaluate(Employee $employee, string $date, array $context): array
    {
        $log = $context['logs'][$employee->id][$date] ?? null;
        $assignment = $this->context->pickAssignment($context['assignments']->get($employee->id, collect()), $date);

        if ($log !== null) {
            return ['status' => $this->fromLog($log), 'log' => $log, 'assignment' => $assignment ?? $this->snapshotAssignment($log)];
        }

        $result = fn (DailyActivityDayStatus $status): array => ['status' => $status, 'log' => null, 'assignment' => $assignment];

        if ($date > $context['today']) {
            return $result(DailyActivityDayStatus::Future);
        }

        $status = $this->context->statusOn($employee, $context['histories']->get($employee->id, collect()), $date);
        if ($status !== EmployeeStatus::Active) {
            return $result(DailyActivityDayStatus::NotEmployed);
        }

        if ($assignment === null) {
            return $result(DailyActivityDayStatus::NotAssigned);
        }

        if (! in_array((int) Carbon::parse($date)->dayOfWeekIso, $context['work_days'], true)) {
            return $result(DailyActivityDayStatus::Weekend);
        }

        if (isset($context['holidays'][$date])) {
            return $result(DailyActivityDayStatus::PublicHoliday);
        }

        if (isset($context['leave'][$employee->id][$date])) {
            return $result(DailyActivityDayStatus::Leave);
        }

        if (! $context['require_submission'] || ($context['tracking_start'] !== null && $date < $context['tracking_start'])) {
            return $result(DailyActivityDayStatus::NotTracked);
        }

        return $result($date === $context['today'] ? DailyActivityDayStatus::Required : DailyActivityDayStatus::Missing);
    }

    private function fromLog(DailyActivityLog $log): DailyActivityDayStatus
    {
        return match ($log->status) {
            DailyActivityStatus::Draft => DailyActivityDayStatus::Draft,
            DailyActivityStatus::ReturnedForCorrection => DailyActivityDayStatus::Returned,
            DailyActivityStatus::Approved => DailyActivityDayStatus::Approved,
            default => DailyActivityDayStatus::Submitted,
        };
    }

    /** A log whose assignment row was since removed still carries its snapshot. */
    private function snapshotAssignment(DailyActivityLog $log): EmployeeAssignment
    {
        return (new EmployeeAssignment)->forceFill([
            'id' => $log->employee_assignment_id,
            'employee_id' => $log->employee_id,
            'organization_id' => $log->organization_id,
            'organization_unit_id' => $log->organization_unit_id,
            'position_id' => $log->position_id,
        ]);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function load(Collection $employees, Carbon $from, Carbon $to): array
    {
        $ids = $employees->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $logs = [];
        if ($ids !== []) {
            DailyActivityLog::query()
                ->whereIn('employee_id', $ids)
                ->betweenDates($fromDate, $toDate)
                ->withCount('items')
                ->get(['id', 'employee_id', 'employee_assignment_id', 'organization_id', 'organization_unit_id', 'position_id', 'activity_date', 'status', 'is_late'])
                ->each(function (DailyActivityLog $log) use (&$logs): void {
                    $logs[$log->employee_id][$log->activity_date->toDateString()] = $log;
                });
        }

        return [
            'today' => $this->settings->today()->toDateString(),
            'work_days' => $this->settings->workWeekDays(),
            'require_submission' => $this->settings->requireDailySubmission(),
            'tracking_start' => $this->settings->trackingStartDate()?->toDateString(),
            'holidays' => $this->calendar->holidaysBetween($from, $to),
            'leave' => $this->leave->fullDayLeaveDates($ids, $from, $to),
            'assignments' => $this->context->assignmentsFor($ids, $from, $to),
            'histories' => $this->context->statusHistoriesFor($ids, $from, $to),
            'logs' => $logs,
        ];
    }

    /** @return array<int, string> */
    private function period(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = Carbon::parse($from->toDateString());
        $end = $to->toDateString();

        while ($cursor->toDateString() <= $end) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
