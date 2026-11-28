<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Queries;

use App\Contracts\EmployeeLeaveProvider;
use Illuminate\Support\Carbon;
use Modules\Leave\Domain\Models\LeaveRequestDay;

/**
 * The real source behind App\Contracts\EmployeeLeaveProvider (ADR 0039,
 * decision 8): approved, full-day leave. Half days are never reported, as
 * the contract requires: the employee worked part of that day.
 */
final class ApprovedLeaveProvider implements EmployeeLeaveProvider
{
    public function fullDayLeaveDates(array $employeeIds, Carbon $from, Carbon $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $dates = [];
        LeaveRequestDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('is_approved', true)->where('is_full_day', true)
            ->whereDate('leave_date', '>=', $from->toDateString())->whereDate('leave_date', '<=', $to->toDateString())
            ->get(['employee_id', 'leave_date'])
            ->each(function (LeaveRequestDay $day) use (&$dates): void {
                $dates[$day->employee_id][$day->leave_date->toDateString()] = true;
            });

        return $dates;
    }
}
