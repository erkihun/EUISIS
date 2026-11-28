<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Backbone\Contracts\WorkingDays;
use App\Services\Calendar\EthiopianCalendarService;
use Illuminate\Support\Carbon;

/**
 * Dates as the leave rules read them: the Ethiopian budget year (Hamle 1 to
 * Sene 30) and the working days of the platform calendar. The module keeps
 * no holiday list of its own.
 *
 * Every date here is a civil date ("Y-m-d"), never an instant, so the
 * timezone of the server cannot move a leave day.
 */
class LeaveCalendar
{
    private const BUSINESS_TIMEZONE = 'Africa/Addis_Ababa';

    private const HAMLE = 11;

    private const SENE = 10;

    public function __construct(
        private readonly EthiopianCalendarService $ethiopian,
        private readonly WorkingDays $workingDays,
    ) {}

    /** Today's civil date in Addis Ababa. */
    public function today(): string
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->toDateString();
    }

    /** Budget year N runs from Hamle 1 of N-1 to Sene 30 of N (Ethiopian calendar). */
    public function budgetYearOf(string $date): int
    {
        $day = Carbon::parse($date);
        $ethiopian = $this->ethiopian->gregorianToEthiopian($day->year, $day->month, $day->day);

        return $ethiopian['month'] >= self::HAMLE ? $ethiopian['year'] + 1 : $ethiopian['year'];
    }

    public function budgetYearStart(int $budgetYear): string
    {
        return $this->ethiopian->ethiopianToGregorian($budgetYear - 1, self::HAMLE, 1)->toDateString();
    }

    public function budgetYearEnd(int $budgetYear): string
    {
        return $this->ethiopian->ethiopianToGregorian($budgetYear, self::SENE, 30)->toDateString();
    }

    public function isWorkingDay(string $date): bool
    {
        return $this->workingDays->isWorkingDay(Carbon::parse($date)->toDateTimeImmutable());
    }

    /**
     * Every civil date from $from to $to, both included.
     *
     * @return list<string>
     */
    public function datesBetween(string $from, string $to): array
    {
        $dates = [];
        $end = Carbon::parse($to);
        for ($day = Carbon::parse($from); $day->lte($end); $day = $day->copy()->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    public function workingDaysBetween(string $from, string $to): int
    {
        return count(array_filter($this->datesBetween($from, $to), fn (string $date): bool => $this->isWorkingDay($date)));
    }

    public function addDays(string $date, int $days): string
    {
        return Carbon::parse($date)->addDays($days)->toDateString();
    }

    public function addMonths(string $date, int $months): string
    {
        return Carbon::parse($date)->addMonthsNoOverflow($months)->toDateString();
    }

    /** Whole months from $from up to (not including) $to; never negative. */
    public function wholeMonthsBetween(string $from, string $to): int
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        return $end->lte($start) ? 0 : (int) floor($start->diffInMonths($end));
    }

    /** Whole years from $from up to (not including) $to; never negative. */
    public function wholeYearsBetween(string $from, string $to): int
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        return $end->lte($start) ? 0 : (int) floor($start->diffInYears($end));
    }
}
