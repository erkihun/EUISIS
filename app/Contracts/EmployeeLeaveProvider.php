<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Source of approved employee leave.
 *
 * EUISIS has no leave module yet. Daily Activity depends on this contract
 * instead of inventing its own leave table, so when a leave module arrives it
 * binds a real implementation and every calendar, missing-activity report and
 * reminder starts honouring leave with no change to the activity code.
 *
 * Partial-day leave is deliberately NOT reported here: an employee on a
 * half-day leave still worked part of the day and may register activity.
 */
interface EmployeeLeaveProvider
{
    /**
     * Dates on which each employee was on APPROVED leave for the whole day.
     *
     * @param  array<int, string>  $employeeIds
     * @return array<string, array<string, true>> employee_id => [Y-m-d => true]
     */
    public function fullDayLeaveDates(array $employeeIds, Carbon $from, Carbon $to): array;
}
