<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Services\Calendar\PublicHolidayCalendar;
use Illuminate\Support\Carbon;

/**
 * The official work calendar Daily Activity measures against: the configured
 * working weekdays plus the shared Public Holidays list.
 *
 * Deliberately not the cafeteria's WorkingDayCalendarService, which answers a
 * different question (is this a subsidy day?) and is shaped by cafeteria
 * special days and scan modes that have nothing to do with office hours.
 *
 * Holiday expansion (recurring Gregorian/Ethiopian) lives in the shared
 * PublicHolidayCalendar so the grievance SLA clock counts the same holidays.
 */
class WorkCalendarService
{
    public function __construct(
        private readonly DailyActivitySettings $settings,
        private readonly PublicHolidayCalendar $holidays,
    ) {}

    public function isWorkingWeekday(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->settings->workWeekDays(), true);
    }

    public function isHoliday(Carbon $date): bool
    {
        return isset($this->holidaysBetween($date, $date)[$date->toDateString()]);
    }

    /** A day on which activity is expected, before employee-specific rules. */
    public function isWorkingDay(Carbon $date): bool
    {
        return $this->isWorkingWeekday($date) && ! $this->isHoliday($date);
    }

    /**
     * @return array<string, array{name_en: string, name_am: ?string}> keyed by Y-m-d
     */
    public function holidaysBetween(Carbon $from, Carbon $to): array
    {
        return $this->holidays->holidaysBetween($from, $to);
    }

    /**
     * Previous working day strictly before $date, looking back at most two
     * weeks (a longer gap means there is nothing sensible to remind about).
     */
    public function previousWorkingDay(Carbon $date): ?Carbon
    {
        $cursor = $date->copy()->startOfDay()->subDay();

        for ($i = 0; $i < 14; $i++) {
            if ($this->isWorkingDay($cursor)) {
                return $cursor;
            }
            $cursor->subDay();
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->holidays->clearCache();
    }
}
