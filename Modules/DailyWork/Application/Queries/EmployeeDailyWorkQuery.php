<?php

declare(strict_types=1);

namespace Modules\DailyWork\Application\Queries;

use App\Models\Employee;
use Modules\DailyWork\Application\Services\DailyActivityCalendarService;
use Modules\DailyWork\Application\Services\DailyActivitySettings;
use Modules\DailyWork\Contracts\Data\DailyActivityStatus;
use Modules\DailyWork\Contracts\EmployeeDailyWork;
use Modules\DailyWork\Domain\Models\DailyActivityLog;

/** My Portal's daily-work panel (moved here from the employee portal controller). */
final class EmployeeDailyWorkQuery implements EmployeeDailyWork
{
    public function __construct(
        private readonly DailyActivitySettings $settings,
        private readonly DailyActivityCalendarService $calendar,
    ) {}

    public function today(): string
    {
        return $this->settings->today()->toDateString();
    }

    public function dashboard(string $employeeId): array
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $today = $this->settings->today();
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $weekStart->copy()->addDays(6);

        $summary = $this->calendar->summaries(collect([$employee]), $weekStart, $today)[$employee->id] ?? [];

        return [
            'today' => $today->toDateString(),
            'today_status' => $this->calendar->dayStatus($employee, $today)->value,
            'week' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'status' => $day['status'],
                'is_late' => $day['is_late'],
                'items_count' => $day['items_count'],
            ], $this->calendar->days($employee, $weekStart, $weekEnd)),
            'required' => (int) ($summary['required'] ?? 0),
            'submitted' => (int) ($summary['submitted'] ?? 0),
            'missing' => (int) ($summary['missing'] ?? 0),
            // All returned days, not just this week: each needs correcting.
            'recent' => DailyActivityLog::query()
                ->where('employee_id', $employee->id)
                ->withCount('items')
                ->orderByDesc('activity_date')
                ->limit(5)
                ->get(['id', 'activity_date', 'status', 'is_late'])
                ->map(fn (DailyActivityLog $log): array => [
                    'date' => $log->activityDateString(),
                    'status' => $log->status->value,
                    'is_late' => $log->is_late,
                    'items_count' => (int) $log->items_count,
                ])
                ->all(),
            'returned' => DailyActivityLog::query()
                ->where('employee_id', $employee->id)
                ->where('status', DailyActivityStatus::ReturnedForCorrection->value)
                ->count(),
        ];
    }
}
