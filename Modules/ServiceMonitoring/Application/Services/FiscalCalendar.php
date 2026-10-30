<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Services\Calendar\EthiopianCalendarService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * The Ethiopian fiscal year and its quarters (D2), computed through the
 * platform's Ethiopian calendar: no Gregorian date is written down anywhere.
 *
 * FY Y runs from Hamle 1 of Y-1 to Sene 30 of Y. Q1 = Hamle, Nehase, Pagume,
 * Meskerem; Q2 = Tikimt, Hidar, Tahsas; Q3 = Tir, Yekatit, Megabit;
 * Q4 = Miyazia, Ginbot, Sene. Ethiopian months: 1 Meskerem … 10 Sene,
 * 11 Hamle, 12 Nehase, 13 Pagume.
 */
final class FiscalCalendar
{
    /** Each quarter's first and last month as [EC month, EC-year offset from the fiscal year]. */
    private const QUARTERS = [
        1 => ['start' => [11, -1], 'end' => [1, 0]],
        2 => ['start' => [2, 0], 'end' => [4, 0]],
        3 => ['start' => [5, 0], 'end' => [7, 0]],
        4 => ['start' => [8, 0], 'end' => [10, 0]],
    ];

    public function __construct(private readonly EthiopianCalendarService $calendar) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function fiscalYear(int $ecYear): array
    {
        return [$this->toGregorian($ecYear - 1, 11, 1), $this->toGregorian($ecYear, 10, 30)];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function quarter(int $ecYear, int $quarter): array
    {
        $q = self::QUARTERS[$quarter] ?? throw new InvalidArgumentException("Quarter must be 1–4, got {$quarter}.");

        return [
            $this->toGregorian($ecYear + $q['start'][1], $q['start'][0], 1),
            $this->toGregorian($ecYear + $q['end'][1], $q['end'][0], 30),
        ];
    }

    /**
     * The default schedule of a period (D5): opens on the first day of the
     * quarter's last month; due 10 days after the quarter ends; sub-city
     * review 5 days later; city review 5 days after that.
     *
     * @return array{starts_on: string, ends_on: string, opens_on: string, submission_due_on: string, subcity_review_due_on: string, city_review_due_on: string}
     */
    public function defaultSchedule(int $ecYear, int $quarter): array
    {
        [$start, $end] = $this->quarter($ecYear, $quarter);
        $lastMonth = self::QUARTERS[$quarter]['end'];
        $opens = $this->toGregorian($ecYear + $lastMonth[1], $lastMonth[0], 1);
        $due = $end->addDays(10);
        $subcity = $due->addDays(5);

        return [
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'opens_on' => $opens->toDateString(),
            'submission_due_on' => $due->toDateString(),
            'subcity_review_due_on' => $subcity->toDateString(),
            'city_review_due_on' => $subcity->addDays(5)->toDateString(),
        ];
    }

    /** The fiscal year a Gregorian day falls in: Hamle–Pagume belong to the next year's FY. */
    public function fiscalYearOf(CarbonImmutable $day): int
    {
        $ec = $this->calendar->gregorianToEthiopian($day->year, $day->month, $day->day);

        return $ec['month'] >= 11 ? $ec['year'] + 1 : $ec['year'];
    }

    /** "Today" in Addis Ababa, as a Y-m-d string. */
    public function today(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(ReportingPeriod::TIMEZONE)->toDateString();
    }

    /** An Ethiopian-calendar label for a Gregorian date, for notices and exports. */
    public function display(?string $date, ?string $locale = null): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $day = CarbonImmutable::parse(substr($date, 0, 10));

        return $this->calendar->formatGregorianDateAsEthiopian($day->toMutable(), $locale ?? app()->getLocale());
    }

    private function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::parse($this->calendar->ethiopianToGregorian($year, $month, $day)->toDateString());
    }
}
