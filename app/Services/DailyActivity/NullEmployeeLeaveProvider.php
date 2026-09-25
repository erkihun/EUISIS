<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Contracts\EmployeeLeaveProvider;
use Illuminate\Support\Carbon;

/**
 * Default binding while EUISIS has no leave module: nobody is on leave.
 *
 * Replace the container binding in AppServiceProvider once leave records
 * exist; nothing else needs to change.
 */
class NullEmployeeLeaveProvider implements EmployeeLeaveProvider
{
    public function fullDayLeaveDates(array $employeeIds, Carbon $from, Carbon $to): array
    {
        return [];
    }
}
