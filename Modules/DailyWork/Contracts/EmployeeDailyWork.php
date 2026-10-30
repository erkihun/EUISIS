<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

/**
 * An employee's daily-work figures for My Portal's dashboard (ADR 0017).
 */
interface EmployeeDailyWork
{
    /** The module's working day, as a Y-m-d string in its timezone. */
    public function today(): string;

    /**
     * Today's state, this week's days, the counts that call for action and the
     * latest logs: today, today_status, week (date, status, is_late,
     * items_count), required, submitted, missing, recent, returned.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $employeeId): array;
}
