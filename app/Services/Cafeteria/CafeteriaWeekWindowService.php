<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaProvider;
use Illuminate\Support\Carbon;

/**
 * Provides the configured cafeteria week window and computes which subsidy
 * days remain from a given scan date through the end of that window,
 * excluding public holidays.
 */
class CafeteriaWeekWindowService
{
    public function __construct(private readonly WorkingDayCalendarService $calendar, private readonly CafeteriaSettingsService $settings) {}

    private function weekday(string $key, int $fallback): int
    {
        return ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6][$this->settings->get($key)] ?? $fallback;
    }

    /** Configured start of the week containing $date. */
    public function weekStart(Carbon $date): Carbon
    {
        return $date->copy()->startOfWeek($this->weekday('week_start_day', Carbon::MONDAY))->startOfDay();
    }

    /** Configured end of the subsidy window containing $date. */
    public function weekEnd(Carbon $date): Carbon
    {
        $offset = ($this->weekday('week_end_day', Carbon::FRIDAY) - $this->weekday('week_start_day', Carbon::MONDAY) + 7) % 7;

        return $this->weekStart($date)->addDays($offset);
    }

    /**
     * Returns an ordered array of date strings ('Y-m-d') representing
     * subsidy days from $from (inclusive) through the end of the same window,
     * excluding weekends and public holidays.
     *
     * @return list<string>
     */
    public function remainingWorkingDaysFrom(Carbon $from, ?CafeteriaProvider $provider = null): array
    {
        $friday = $this->weekEnd($from);
        $current = $from->copy()->startOfDay();
        $days = [];

        while ($current->lte($friday)) {
            if ($this->calendar->isSubsidyDay($current, $provider)) {
                $days[] = $current->toDateString();
            }
            $current->addDay();
        }

        return $days;
    }

    /** True when $date falls on Saturday or Sunday. */
    public function isWeekend(Carbon $date): bool
    {
        return $date->isWeekend();
    }

    /**
     * True when $date is a cafeteria subsidy day under the configured calendar.
     */
    public function isCafeteriaWorkingDay(Carbon $date): bool
    {
        return $this->calendar->isWorkingDay($date);
    }
}
