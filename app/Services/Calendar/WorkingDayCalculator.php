<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;

/**
 * Working-day arithmetic over the shared public-holiday list. The caller
 * supplies the working weekdays (each module owns its work-week policy);
 * holidays always come from PublicHolidayCalendar so no module keeps its own
 * holiday logic.
 *
 * Convention: counting starts the day AFTER $start, so "3 working days from
 * Monday" is Thursday (with no holidays), and a deadline is the END of that
 * day in the application timezone.
 */
class WorkingDayCalculator
{
    /** Hard stop so a misconfigured calendar (every day a holiday) cannot loop forever. */
    private const MAX_SCAN_DAYS = 3660;

    public function __construct(private readonly PublicHolidayCalendar $holidays) {}

    /** @param  list<int>  $workWeekDays ISO weekdays (1 = Monday) */
    public function isWorkingDay(Carbon $date, array $workWeekDays): bool
    {
        return in_array($date->dayOfWeekIso, $workWeekDays, true) && ! $this->holidays->isHoliday($date);
    }

    /**
     * End of the $days-th working day after $start.
     *
     * @param  list<int>  $workWeekDays
     */
    public function addWorkingDays(Carbon $start, int $days, array $workWeekDays): Carbon
    {
        $cursor = $start->copy()->startOfDay();
        if ($days <= 0) {
            return $start->copy()->endOfDay();
        }

        // Prefetch a generous window once; past it (only with long holiday
        // runs) fall back to a per-day lookup.
        $rangeEnd = $cursor->copy()->addDays(min(self::MAX_SCAN_DAYS, $days * 3 + 30))->toDateString();
        $holidays = $this->holidays->holidaysBetween($cursor, Carbon::parse($rangeEnd));
        $counted = 0;
        for ($i = 0; $i < self::MAX_SCAN_DAYS; $i++) {
            $cursor->addDay();
            if (! in_array($cursor->dayOfWeekIso, $workWeekDays, true)) {
                continue;
            }
            $key = $cursor->toDateString();
            $isHoliday = $key <= $rangeEnd ? isset($holidays[$key]) : $this->holidays->isHoliday($cursor);
            if ($isHoliday) {
                continue;
            }
            if (++$counted === $days) {
                return $cursor->endOfDay();
            }
        }

        return $cursor->endOfDay();
    }

    /** End of the day $days calendar days after $start. */
    public function addCalendarDays(Carbon $start, int $days): Carbon
    {
        return $start->copy()->addDays(max(0, $days))->endOfDay();
    }

    /**
     * Working days strictly after $from up to and including $to (0 when $to <= $from).
     *
     * @param  list<int>  $workWeekDays
     */
    public function workingDaysBetween(Carbon $from, Carbon $to, array $workWeekDays): int
    {
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        if ($end->lte($cursor)) {
            return 0;
        }

        $holidays = $this->holidays->holidaysBetween($cursor, $end);
        $count = 0;
        for ($i = 0; $i < self::MAX_SCAN_DAYS && $cursor->lt($end); $i++) {
            $cursor->addDay();
            if (in_array($cursor->dayOfWeekIso, $workWeekDays, true) && ! isset($holidays[$cursor->toDateString()])) {
                $count++;
            }
        }

        return $count;
    }
}
