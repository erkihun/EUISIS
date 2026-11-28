<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use Modules\Leave\Domain\Models\LeaveType;

/**
 * Turns a period into its leave days. A working-day type counts only the
 * working days of the platform calendar; a calendar-day type counts every
 * day. The rest days inside a working-day leave are kept as uncounted rows,
 * so nothing else can be asked for them. A half-day leave counts half a day
 * on each of its days, whether it is one day or several.
 */
class LeaveDayCounter
{
    public function __construct(private readonly LeaveCalendar $calendar) {}

    /**
     * @return list<array{leave_date: string, portion: float, is_full_day: bool, is_counted: bool}>
     */
    public function days(LeaveType $type, string $from, string $to, bool $halfDay = false): array
    {
        $rows = [];
        foreach ($this->calendar->datesBetween($from, $to) as $date) {
            $counted = ! $type->countsWorkingDays() || $this->calendar->isWorkingDay($date);
            $rows[] = [
                'leave_date' => $date,
                'portion' => $halfDay ? 0.5 : 1.0,
                'is_full_day' => ! $halfDay,
                'is_counted' => $counted,
            ];
        }

        return $rows;
    }

    /** @param list<array{portion: float, is_counted: bool}> $rows */
    public function total(array $rows): float
    {
        return (float) array_sum(array_map(static fn (array $row): float => $row['is_counted'] ? (float) $row['portion'] : 0.0, $rows));
    }
}
