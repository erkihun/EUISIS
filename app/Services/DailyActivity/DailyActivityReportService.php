<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\DailyActivityDayStatus;
use App\Enums\DailyActivityStatus;
use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Support\Carbon;

/**
 * The eight Daily Activity reports.
 *
 * Every report is built from the same DailyActivityCoverage and the same
 * day calculation as the dashboards, so an export can never show a figure
 * the screen does not. Counts describe registration discipline only: nothing
 * here scores performance, and item counts are shown as volume, not merit.
 */
class DailyActivityReportService
{
    public const TYPES = [
        'daily_submission',
        'employee_activity',
        'unit_activity',
        'missing',
        'late',
        'review_status',
        'by_task',
        'monthly_summary',
        // Work execution register: measured against task standards.
        'task_performance',
        'sub_service_performance',
    ];

    /** Rows returned for on-screen display; exports use EXPORT_LIMIT. */
    public const SCREEN_LIMIT = 500;

    public const EXPORT_LIMIT = 20000;

    public function __construct(
        private readonly DailyActivityQueryService $queries,
        private readonly DailyActivityCalendarService $calendar,
        private readonly DailyActivitySettings $settings,
        private readonly DailyWorkPerformanceCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, columns: array<int, string>, rows: array<int, array<string, mixed>>, truncated: bool, total: int, from: string, to: string}
     */
    public function build(string $type, DailyActivityCoverage $coverage, array $filters, int $limit = self::SCREEN_LIMIT): array
    {
        $today = $this->settings->today();

        [$from, $to] = match ($type) {
            'daily_submission' => $this->queries->range($filters['date'] ?? $filters['date_to'] ?? null, $filters['date'] ?? $filters['date_to'] ?? null, $today),
            'monthly_summary' => $this->monthRange($filters['month'] ?? null, $today),
            default => $this->queries->range($filters['date_from'] ?? null, $filters['date_to'] ?? null, $today, 6),
        };

        // Never report the future as missing.
        if ($to->toDateString() > $today->toDateString()) {
            $to = Carbon::parse($today->toDateString());
        }
        if ($from->greaterThan($to)) {
            $from = $to->copy();
        }

        $filters = [...$filters, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];

        [$columns, $rows] = match ($type) {
            'daily_submission' => $this->dailySubmission($coverage, $from, $filters, $limit),
            'employee_activity' => $this->employeeActivity($coverage, $filters, $limit),
            'unit_activity' => $this->unitActivity($coverage, $from, $to, $filters),
            'missing' => $this->missing($coverage, $from, $to, $filters, $limit),
            'late' => $this->late($coverage, $filters, $limit),
            'review_status' => $this->reviewStatus($coverage, $filters, $limit),
            'by_task' => $this->byTask($coverage, $filters),
            'monthly_summary' => $this->monthlySummary($coverage, $from, $to, $filters, $limit),
            'task_performance' => $this->taskPerformance($coverage, $filters),
            'sub_service_performance' => $this->subServicePerformance($coverage, $filters),
        };

        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'type' => $type,
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
            // Row-limited reports stop collecting once past the limit, so a
            // truncated report reports what it shows, never a guessed total.
            'total' => count($rows),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    /** @return array{0: array<int, string>, 1: array<int, array<string, mixed>>} */
    private function dailySubmission(DailyActivityCoverage $coverage, Carbon $date, array $filters, int $limit): array
    {
        $rows = [];
        $this->eachDayRow($coverage, $date, $date, $filters, function (array $row) use (&$rows, $limit): bool {
            $rows[] = $row;

            return count($rows) <= $limit;
        });
        $names = $this->placementNames($rows);

        return [
            ['date', 'employee_number', 'employee', 'organization_unit', 'position', 'day_status', 'submitted_at', 'is_late', 'items'],
            array_map(fn (array $row): array => [
                'date' => $row['date'],
                'employee_number' => $row['employee']->employee_number,
                'employee' => $row['employee']->full_name,
                'organization_unit' => $names['units'][$row['assignment']->organization_unit_id] ?? null,
                'position' => $names['positions'][$row['assignment']->position_id] ?? null,
                'day_status' => $row['missing'] ? DailyActivityDayStatus::Missing->value : $row['status']->value,
                'submitted_at' => $row['log']?->submitted_at?->toIso8601String(),
                'is_late' => (bool) ($row['log']?->is_late ?? false),
                'items' => (int) ($row['log']?->items_count ?? 0),
            ], $rows),
        ];
    }

    private function employeeActivity(DailyActivityCoverage $coverage, array $filters, int $limit): array
    {
        $items = DailyActivityItem::query()
            ->whereIn('daily_activity_log_id', $this->queries->logs($coverage, $filters)->select('id'))
            ->with(['log' => fn ($q) => $q->with(DailyActivityPresenter::SUMMARY_WITH), 'positionService:id,name_en,name_am'])
            ->orderBy(DailyActivityLog::query()->select('activity_date')->whereColumn('daily_activity_logs.id', 'daily_activity_items.daily_activity_log_id'))
            ->orderBy('sort_order')
            ->limit($limit + 1)
            ->get();

        return [
            ['date', 'employee_number', 'employee', 'organization_unit', 'task', 'title', 'description', 'output_result', 'progress_status', 'status'],
            $items->map(fn (DailyActivityItem $item): array => [
                'date' => $item->log->activityDateString(),
                'employee_number' => $item->log->employee?->employee_number,
                'employee' => $item->log->employee?->full_name,
                'organization_unit' => $this->localized($item->log->organizationUnit?->name_en, $item->log->organizationUnit?->name_am),
                'task' => $item->positionService
                    ? $this->localized($item->positionService->name_en, $item->positionService->name_am)
                    : ($item->activity_category ? __('daily-activities.categories.'.$item->activity_category->value) : __('daily-activities.categories.other')),
                'title' => $item->title,
                'description' => $item->description,
                'output_result' => $item->output_result,
                'progress_status' => $item->progress_status?->value,
                'status' => $item->log->status->value,
            ])->all(),
        ];
    }

    private function unitActivity(DailyActivityCoverage $coverage, Carbon $from, Carbon $to, array $filters): array
    {
        $units = [];

        // An aggregate: every in-scope employee-day counts, one bounded batch at a time.
        $this->eachDayRow($coverage, $from, $to, $filters, function (array $row) use (&$units): void {
            $key = $row['assignment']->organization_unit_id ?? '—';
            $units[$key] ??= ['employees' => [], 'required' => 0, 'submitted' => 0, 'approved' => 0, 'missing' => 0, 'returned' => 0, 'late' => 0, 'leave' => 0, 'items' => 0];
            $unit = &$units[$key];
            $unit['employees'][$row['employee']->id] = true;
            $this->accumulate($unit, $row);
            unset($unit);
        });

        $names = OrganizationUnit::query()->whereIn('id', array_keys($units))->get(['id', 'name_en', 'name_am'])->keyBy('id');

        return [
            ['organization_unit', 'employees', 'required', 'submitted', 'approved', 'returned', 'missing', 'late', 'leave', 'items'],
            collect($units)->map(fn (array $unit, string $id): array => [
                'organization_unit' => $names->has($id) ? $this->localized($names[$id]->name_en, $names[$id]->name_am) : '—',
                'employees' => count($unit['employees']),
                'required' => $unit['required'],
                'submitted' => $unit['submitted'],
                'approved' => $unit['approved'],
                'returned' => $unit['returned'],
                'missing' => $unit['missing'],
                'late' => $unit['late'],
                'leave' => $unit['leave'],
                'items' => $unit['items'],
            ])->sortBy('organization_unit')->values()->all(),
        ];
    }

    private function missing(DailyActivityCoverage $coverage, Carbon $from, Carbon $to, array $filters, int $limit): array
    {
        // Employees are visited in name order; collection stops one row past
        // the limit, which is enough to flag the result as truncated.
        $rows = [];
        $this->eachDayRow($coverage, $from, $to, $filters, function (array $row) use (&$rows, $limit): bool {
            if ($row['missing']) {
                $rows[] = $row;
            }

            return count($rows) <= $limit;
        });
        $names = $this->placementNames($rows);

        usort($rows, fn (array $a, array $b): int => [$b['date'], $a['employee']->full_name] <=> [$a['date'], $b['employee']->full_name]);

        return [
            ['date', 'employee_number', 'employee', 'organization_unit', 'position', 'day_status'],
            array_map(fn (array $row): array => [
                'date' => $row['date'],
                'employee_number' => $row['employee']->employee_number,
                'employee' => $row['employee']->full_name,
                'organization_unit' => $names['units'][$row['assignment']->organization_unit_id] ?? null,
                'position' => $names['positions'][$row['assignment']->position_id] ?? null,
                // A stale draft is reported as missing, but labelled as a draft.
                'day_status' => $row['status']->value,
            ], $rows),
        ];
    }

    private function late(DailyActivityCoverage $coverage, array $filters, int $limit): array
    {
        $logs = $this->queries->logs($coverage, [...$filters, 'late' => true])
            ->with(DailyActivityPresenter::SUMMARY_WITH)
            ->orderByDesc('activity_date')
            ->limit($limit + 1)
            ->get();

        return [
            ['date', 'employee_number', 'employee', 'organization_unit', 'submitted_at', 'late_reason', 'status'],
            $logs->map(fn (DailyActivityLog $log): array => [
                'date' => $log->activityDateString(),
                'employee_number' => $log->employee?->employee_number,
                'employee' => $log->employee?->full_name,
                'organization_unit' => $this->localized($log->organizationUnit?->name_en, $log->organizationUnit?->name_am),
                'submitted_at' => $log->first_submitted_at?->toIso8601String() ?? $log->submitted_at?->toIso8601String(),
                'late_reason' => $log->late_reason,
                'status' => $log->status->value,
            ])->all(),
        ];
    }

    private function reviewStatus(DailyActivityCoverage $coverage, array $filters, int $limit): array
    {
        $now = $this->settings->now();
        $logs = $this->queries->logs($coverage, $filters)
            ->where('status', '!=', DailyActivityStatus::Draft->value)
            ->with(DailyActivityPresenter::SUMMARY_WITH)
            ->orderByDesc('activity_date')
            ->limit($limit + 1)
            ->get();

        return [
            ['date', 'employee_number', 'employee', 'organization_unit', 'status', 'submitted_at', 'reviewed_at', 'reviewer', 'waiting_days'],
            $logs->map(fn (DailyActivityLog $log): array => [
                'date' => $log->activityDateString(),
                'employee_number' => $log->employee?->employee_number,
                'employee' => $log->employee?->full_name,
                'organization_unit' => $this->localized($log->organizationUnit?->name_en, $log->organizationUnit?->name_am),
                'status' => $log->status->value,
                'submitted_at' => $log->submitted_at?->toIso8601String(),
                'reviewed_at' => $log->reviewed_at?->toIso8601String(),
                'reviewer' => $log->reviewer?->name,
                'waiting_days' => $log->status->isAwaitingReview() && $log->submitted_at
                    ? (int) $log->submitted_at->diffInDays($now)
                    : null,
            ])->all(),
        ];
    }

    private function byTask(DailyActivityCoverage $coverage, array $filters): array
    {
        $grouped = DailyActivityItem::query()
            ->whereIn('daily_activity_log_id', $this->queries->logs($coverage, $filters)
                ->whereIn('status', DailyActivityStatus::submittedValues())
                ->select('id'))
            ->leftJoin('position_services', 'position_services.id', '=', 'daily_activity_items.position_service_id')
            ->join('daily_activity_logs', 'daily_activity_logs.id', '=', 'daily_activity_items.daily_activity_log_id')
            ->selectRaw('daily_activity_items.position_service_id as service_id, daily_activity_items.activity_category as category, position_services.name_en as service_en, position_services.name_am as service_am')
            ->selectRaw('COUNT(*) as items, COUNT(DISTINCT daily_activity_logs.employee_id) as employees')
            ->selectRaw("SUM(CASE WHEN daily_activity_items.progress_status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN daily_activity_items.progress_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->selectRaw("SUM(CASE WHEN daily_activity_items.progress_status = 'blocked' THEN 1 ELSE 0 END) as blocked")
            ->selectRaw("SUM(CASE WHEN daily_activity_items.progress_status = 'carried_forward' THEN 1 ELSE 0 END) as carried_forward")
            ->groupBy('daily_activity_items.position_service_id', 'daily_activity_items.activity_category', 'position_services.name_en', 'position_services.name_am')
            ->orderByDesc('items')
            ->get();

        return [
            ['task', 'source', 'items', 'employees', 'completed', 'in_progress', 'blocked', 'carried_forward'],
            $grouped->map(fn ($row): array => [
                'task' => $row->service_id
                    ? $this->localized($row->service_en, $row->service_am)
                    : __('daily-activities.categories.'.($row->category ?: 'other')),
                'source' => $row->service_id ? 'position_service' : 'other_activity',
                'items' => (int) $row->items,
                'employees' => (int) $row->employees,
                'completed' => (int) $row->completed,
                'in_progress' => (int) $row->in_progress,
                'blocked' => (int) $row->blocked,
                'carried_forward' => (int) $row->carried_forward,
            ])->all(),
        ];
    }

    /**
     * Plan against actual per main task: executions, employees, planned and
     * actual quantity, and the average of each dimension's score over the
     * task's measured items. Submitted days only, within scope. One grouped
     * query: the number of rows is the number of tasks, never of items.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    private function taskPerformance(DailyActivityCoverage $coverage, array $filters): array
    {
        $rows = $this->taskRows($coverage, $filters);

        return [
            ['main_service', 'sub_service', 'main_task', 'executions', 'employees', 'planned_quantity', 'actual_quantity', 'quantity_score', 'time_score', 'quality_score', 'task_score'],
            $rows->map(fn ($row): array => [
                'main_service' => $this->localized($row->service_en, $row->service_am),
                'sub_service' => trim($row->sub_code.' '.$this->localized($row->sub_en, $row->sub_am)),
                'main_task' => trim($row->task_code.' '.$this->localized($row->task_en, $row->task_am)),
                'executions' => (int) $row->executions,
                'employees' => (int) $row->employees,
                'planned_quantity' => $this->number($row->planned_quantity),
                'actual_quantity' => $this->number($row->actual_quantity),
                'quantity_score' => $this->percent($row->quantity_score),
                'time_score' => $this->percent($row->time_score),
                'quality_score' => $this->percent($row->quality_score),
                'task_score' => $this->percent($row->task_score),
            ])->all(),
        ];
    }

    /**
     * The form's sub-service aggregate: (Task 1 + Task 2 + …) / number of
     * tasks, where a task's performance is the average over its executions
     * and only tasks executed in the period count (docs/daily-work-register.md
     * records this as the rule awaiting confirmation). Tasks of different
     * sub-services are never mixed.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    private function subServicePerformance(DailyActivityCoverage $coverage, array $filters): array
    {
        $bySub = $this->taskRows($coverage, $filters)->groupBy('sub_service_id');

        $rows = $bySub->map(function ($tasks): array {
            $first = $tasks->first();
            $scores = $tasks->pluck('task_score')->filter(fn ($score) => $score !== null)->map(fn ($score) => $this->decimalString($score))->values()->all();

            return [
                'main_service' => $this->localized($first->service_en, $first->service_am),
                'sub_service' => trim($first->sub_code.' '.$this->localized($first->sub_en, $first->sub_am)),
                'tasks_executed' => $tasks->count(),
                'executions' => (int) $tasks->sum('executions'),
                'sub_service_score' => $this->percent($this->calculator->subServiceScore($scores)),
            ];
        })->values()->all();

        return [['main_service', 'sub_service', 'tasks_executed', 'executions', 'sub_service_score'], $rows];
    }

    /** @param array<string, mixed> $filters */
    private function taskRows(DailyActivityCoverage $coverage, array $filters): \Illuminate\Support\Collection
    {
        return DailyActivityItem::query()
            ->whereNotNull('daily_activity_items.task_standard_id')
            ->whereIn('daily_activity_log_id', $this->queries->logs($coverage, $filters)
                ->whereIn('status', DailyActivityStatus::submittedValues())
                ->select('id'))
            ->join('daily_activity_logs', 'daily_activity_logs.id', '=', 'daily_activity_items.daily_activity_log_id')
            ->join('position_service_tasks', 'position_service_tasks.id', '=', 'daily_activity_items.task_id')
            ->join('position_service_sub_services', 'position_service_sub_services.id', '=', 'daily_activity_items.sub_service_id')
            ->join('position_services', 'position_services.id', '=', 'daily_activity_items.position_service_id')
            ->selectRaw('daily_activity_items.task_id, daily_activity_items.sub_service_id')
            ->selectRaw('position_services.name_en as service_en, position_services.name_am as service_am')
            ->selectRaw('position_service_sub_services.code as sub_code, position_service_sub_services.name_en as sub_en, position_service_sub_services.name_am as sub_am')
            ->selectRaw('position_service_tasks.code as task_code, position_service_tasks.name_en as task_en, position_service_tasks.name_am as task_am')
            ->selectRaw('COUNT(*) as executions, COUNT(DISTINCT daily_activity_logs.employee_id) as employees')
            ->selectRaw('SUM(daily_activity_items.planned_quantity) as planned_quantity, SUM(daily_activity_items.quantity) as actual_quantity')
            ->selectRaw('AVG(daily_activity_items.quantity_score) as quantity_score, AVG(daily_activity_items.time_score) as time_score')
            ->selectRaw('AVG(daily_activity_items.quality_score) as quality_score, AVG(daily_activity_items.task_score) as task_score')
            ->groupBy(
                'daily_activity_items.task_id', 'daily_activity_items.sub_service_id',
                'position_services.name_en', 'position_services.name_am',
                'position_service_sub_services.code', 'position_service_sub_services.name_en', 'position_service_sub_services.name_am',
                'position_service_tasks.code', 'position_service_tasks.name_en', 'position_service_tasks.name_am',
            )
            ->orderBy('position_service_sub_services.code')
            ->orderBy('position_service_tasks.code')
            ->get();
    }

    /** A score as a percent with two decimals, never capped. */
    private function percent(mixed $value): ?string
    {
        return $value === null ? null : $this->calculator->round($this->decimalString($value), 2);
    }

    private function number(mixed $value): ?string
    {
        return $value === null ? null : rtrim(rtrim($this->calculator->round($this->decimalString($value), 4), '0'), '.');
    }

    /** NUMERIC from PostgreSQL arrives as a string; other drivers may return a float. */
    private function decimalString(mixed $value): string
    {
        return is_float($value) ? sprintf('%.10F', $value) : (string) $value;
    }

    private function monthlySummary(DailyActivityCoverage $coverage, Carbon $from, Carbon $to, array $filters, int $limit): array
    {
        $inScope = $this->queries->assignmentPredicate($coverage, $filters);
        $month = $from->format('Y-m');
        $rows = [];

        $this->queries->eachEmployeeChunk($coverage, $from, $to, $filters, function ($employees) use (&$rows, $from, $to, $inScope, $month, $limit): bool {
            $summaries = $this->calendar->summaries($employees, $from, $to, $inScope);
            foreach ($employees as $employee) {
                $summary = $summaries[$employee->id] ?? null;
                if ($summary === null || $summary['last_assignment'] === null) {
                    continue;
                }
                $rows[] = [
                    'month' => $month,
                    'employee_number' => $employee->employee_number,
                    'employee' => $employee->full_name,
                    'required' => $summary['required'],
                    'submitted' => $summary['submitted'],
                    'approved' => $summary['approved'],
                    'missing' => $summary['missing'],
                    'leave' => $summary['leave'],
                    'holiday' => $summary['holiday'],
                    'late' => $summary['late'],
                    'items' => $summary['items'],
                ];
                if (count($rows) > $limit) {
                    return false;
                }
            }

            return true;
        });

        return [
            ['month', 'employee_number', 'employee', 'required', 'submitted', 'approved', 'missing', 'leave', 'holiday', 'late', 'items'],
            $rows,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Visit every in-scope employee-day, one bounded employee batch at a
     * time. Return false from $visit to stop early.
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(array<string, mixed>): (bool|void)  $visit
     */
    private function eachDayRow(DailyActivityCoverage $coverage, Carbon $from, Carbon $to, array $filters, callable $visit): void
    {
        $inScope = $this->queries->assignmentPredicate($coverage, $filters);

        $this->queries->eachEmployeeChunk($coverage, $from, $to, $filters, function ($employees) use ($from, $to, $inScope, $visit): bool {
            foreach ($this->calendar->rows($employees, $from, $to, $inScope) as $row) {
                if ($visit($row) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @param array<string, mixed> $bucket */
    private function accumulate(array &$bucket, array $row): void
    {
        /** @var DailyActivityDayStatus $status */
        $status = $row['status'];

        if ($status === DailyActivityDayStatus::Leave) {
            $bucket['leave']++;
        }
        if ($row['log'] !== null) {
            $bucket['items'] += (int) $row['log']->items_count;
            if ($row['log']->is_late && $row['log']->status->countsAsSubmitted()) {
                $bucket['late']++;
            }
        }
        if (! $status->isRequiredDay()) {
            return;
        }
        $bucket['required']++;
        if ($row['missing']) {
            $bucket['missing']++;
        }
        if (in_array($status, [DailyActivityDayStatus::Submitted, DailyActivityDayStatus::Approved], true)) {
            $bucket['submitted']++;
        }
        if ($status === DailyActivityDayStatus::Approved) {
            $bucket['approved']++;
        }
        if ($status === DailyActivityDayStatus::Returned) {
            $bucket['returned']++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{units: array<string, string>, positions: array<string, string>}
     */
    private function placementNames(array $rows): array
    {
        $assignments = collect($rows)->pluck('assignment');
        $unitIds = $assignments->pluck('organization_unit_id')->filter()->unique()->values();
        $positionIds = $assignments->pluck('position_id')->filter()->unique()->values();

        return [
            'units' => OrganizationUnit::query()->whereIn('id', $unitIds)->get(['id', 'name_en', 'name_am'])
                ->mapWithKeys(fn (OrganizationUnit $u): array => [$u->id => $this->localized($u->name_en, $u->name_am)])->all(),
            'positions' => Position::query()->whereIn('id', $positionIds)->get(['id', 'title_en', 'title_am'])
                ->mapWithKeys(fn (Position $p): array => [$p->id => $this->localized($p->title_en, $p->title_am)])->all(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function monthRange(?string $month, Carbon $today): array
    {
        $start = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? Carbon::createFromFormat('!Y-m', $month)
            : Carbon::parse($today->toDateString())->startOfMonth();

        return [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()->startOfDay()];
    }

    private function localized(?string $en, ?string $am): ?string
    {
        return app()->getLocale() === 'am' && filled($am) ? $am : ($en ?? $am);
    }
}
