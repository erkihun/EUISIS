<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Domain\Calendar\PeriodCalculator;
use Modules\Reporting\Domain\Calendar\ReportingPeriod;
use Modules\Reporting\Domain\Enums\CalendarBasis;
use Modules\Reporting\Domain\Enums\Frequency;
use Modules\Reporting\Domain\Enums\HolidayAdjustment;

/**
 * Turns a schedule's rules into concrete moments for one period.
 *
 * Deadlines are set in the schedule's business timezone (Africa/Addis_Ababa
 * by default) and returned in UTC, which is what is stored. The interface
 * language plays no part: the same schedule gives the same instant in
 * Amharic and in English.
 */
final readonly class CyclePlanner
{
    public function __construct(private PeriodCalculator $periods, private WorkingCalendar $calendar) {}

    /**
     * @param  array{due_offset_days: int, opens_offset_days: int, due_time: string, timezone: string, holiday_adjustment: string}  $rules
     * @return array{opens_at: DateTimeImmutable, due_at: DateTimeImmutable} both in UTC
     */
    public function moments(array $rules, ReportingPeriod $period): array
    {
        $zone = new DateTimeZone($rules['timezone']);
        $utc = new DateTimeZone('UTC');
        $end = new DateTimeImmutable($period->endDate.' 00:00:00', $zone);

        $due = $end->modify(sprintf('%+d days', $rules['due_offset_days']))->modify($rules['due_time'].':00');
        $due = $this->calendar->adjust($due, HolidayAdjustment::from($rules['holiday_adjustment']));
        $opens = $end->modify(sprintf('%+d days', $rules['opens_offset_days']));
        if ($opens > $due) {
            $opens = $due->modify('-1 day');
        }

        return ['opens_at' => $opens->setTimezone($utc), 'due_at' => $due->setTimezone($utc)];
    }

    /**
     * The next periods of a schedule with their moments, for the preview shown before publishing.
     *
     * @param  array{frequency: string, calendar_basis: string, fiscal_year_start_month: int, due_offset_days: int, opens_offset_days: int, due_time: string, timezone: string, holiday_adjustment: string}  $rules
     * @return list<array{period: ReportingPeriod, opens_at: DateTimeImmutable, due_at: DateTimeImmutable}>
     */
    public function preview(array $rules, string $fromIso, int $count = 6): array
    {
        $frequency = Frequency::from($rules['frequency']);
        if (! $frequency->recurs()) {
            return [];
        }
        $basis = CalendarBasis::from($rules['calendar_basis']);
        $period = $this->periods->containing($frequency, $basis, $fromIso, $rules['fiscal_year_start_month']);
        $rows = [];
        for ($i = 0; $i < min($count, 24); $i++) {
            $rows[] = ['period' => $period, ...$this->moments($rules, $period)];
            $period = $this->periods->following($period, $frequency, $basis, $rules['fiscal_year_start_month']);
        }

        return $rows;
    }
}
