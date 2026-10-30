<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts;

use Carbon\CarbonInterface;

/**
 * One employee's own cafeteria use, for self-service pages (My Portal): the
 * day, week, month and year totals, the daily subsidy rate, the consumed
 * days and holidays over a window wide enough to page through the
 * Ethiopian or Gregorian calendar, and the latest meals. Read from the
 * cafeteria platform's API when it serves the portal (ADR 0014), otherwise
 * from the local records.
 */
interface EmployeeCafeteriaActivity
{
    /**
     * Plain values only, in the shape My Portal's cafeteria card has always
     * received: type ('cafeteria'), daily, weekly, monthly, yearly, employee,
     * today, consumed (Y-m-d => subsidy), holidays (Y-m-d list),
     * window_start, window_end, transactions (latest fifteen), and platform
     * when the platform answered.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(string $employeeId, CarbonInterface $today): array;
}
