<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

use Illuminate\Support\Carbon;

/**
 * The calendar settings daily work runs on (ADR 0017): the working week and
 * the module's day, for the platform work calendar and task standards.
 */
interface DailyWorkCalendarSettings
{
    /** ISO weekday numbers (1 = Monday) that count as working days. */
    public function workWeekDays(): array;

    public function timezone(): string;

    /** The start of the module's current day in its timezone. */
    public function today(): Carbon;
}
