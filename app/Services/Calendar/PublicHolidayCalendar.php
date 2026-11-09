<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;

/**
 * The shared Public Holidays list, with recurring holidays expanded.
 *
 * Recurring holidays are stored once with a recurrence_type; here a Gregorian
 * one repeats on the same month/day and an Ethiopian one on the same
 * Ethiopian month/day (so Meskel stays on Meskerem 17 whether that lands on
 * 27 or 28 September). Used by Daily Activity's work calendar and the
 * grievance SLA clock, so both count the same holidays.
 *
 * Deliberately not the cafeteria's WorkingDayCalendarService, which answers
 * a different question (is this a subsidy day?).
 */
class PublicHolidayCalendar
{
    /** @var array<string, array<string, array{name_en: string, name_am: ?string}>> "from|to" => [Y-m-d => label] */
    private array $holidayCache = [];

    public function __construct(private readonly EthiopianCalendarService $ethiopian) {}

    public function isHoliday(Carbon $date): bool
    {
        return isset($this->holidaysBetween($date, $date)[$date->toDateString()]);
    }

    /**
     * @return array<string, array{name_en: string, name_am: ?string}> keyed by Y-m-d
     */
    public function holidaysBetween(Carbon $from, Carbon $to): array
    {
        $key = $from->toDateString().'|'.$to->toDateString();

        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $result = [];

        $holidays = PublicHoliday::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                // Upper bound at end of day: SQLite stores date casts with a
                // 00:00:00 time, which a bare Y-m-d bound would exclude.
                ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString().' 23:59:59'])
                ->orWhere('is_recurring', true))
            ->get(['holiday_date', 'is_recurring', 'recurrence_type', 'name_en', 'name_am']);

        foreach ($holidays as $holiday) {
            $label = ['name_en' => (string) $holiday->name_en, 'name_am' => $holiday->name_am];

            foreach ($this->occurrences($holiday, $start, $end) as $date) {
                $result[$date] ??= $label;
            }
        }

        ksort($result);

        return $this->holidayCache[$key] = $result;
    }

    public function clearCache(): void
    {
        $this->holidayCache = [];
    }

    /** @return array<int, string> */
    private function occurrences(PublicHoliday $holiday, Carbon $start, Carbon $end): array
    {
        // Compare calendar dates as strings: $start may carry the module
        // timezone while stored dates parse in UTC, and an instant comparison
        // would shift the day boundary by three hours.
        $base = Carbon::parse($holiday->holiday_date);
        $from = $start->toDateString();
        $to = $end->toDateString();
        $inRange = static fn (string $date): bool => $date >= $from && $date <= $to;

        if (! $holiday->is_recurring) {
            return $inRange($base->toDateString()) ? [$base->toDateString()] : [];
        }

        $dates = [];

        if ($holiday->recurrence_type === 'ethiopian') {
            $eth = $this->ethiopian->gregorianToEthiopian($base->year, $base->month, $base->day);
            $firstYear = $this->ethiopian->gregorianToEthiopian($start->year, $start->month, $start->day)['year'];
            $lastYear = $this->ethiopian->gregorianToEthiopian($end->year, $end->month, $end->day)['year'];

            for ($year = $firstYear; $year <= $lastYear; $year++) {
                if (! $this->ethiopian->isValidEthiopianDate($year, $eth['month'], $eth['day'])) {
                    continue;
                }
                $date = $this->ethiopian->ethiopianToGregorian($year, $eth['month'], $eth['day'])->toDateString();
                if ($inRange($date)) {
                    $dates[] = $date;
                }
            }

            return $dates;
        }

        for ($year = $start->year; $year <= $end->year; $year++) {
            if (! checkdate($base->month, $base->day, $year)) {
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $base->month, $base->day);
            if ($inRange($date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}
