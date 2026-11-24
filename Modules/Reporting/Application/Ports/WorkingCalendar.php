<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use DateTimeImmutable;
use Modules\Reporting\Domain\Enums\HolidayAdjustment;

/** Working days and public holidays, from whoever owns the calendar. */
interface WorkingCalendar
{
    /** Moves a deadline off a weekend or holiday as the schedule says; the time of day is kept. */
    public function adjust(DateTimeImmutable $moment, HolidayAdjustment $adjustment): DateTimeImmutable;

    public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable;
}
