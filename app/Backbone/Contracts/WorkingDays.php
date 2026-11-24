<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The city's working calendar: the configured work week and the public
 * holidays (fixed and Ethiopian-calendar ones). Dates are read in the
 * business timezone, Africa/Addis_Ababa.
 */
interface WorkingDays
{
    public function isWorkingDay(DateTimeInterface $date): bool;

    /** The same instant moved forward by whole working days (time of day kept). */
    public function addWorkingDays(DateTimeInterface $from, int $days): DateTimeImmutable;

    /** The date itself when it is a working day, otherwise the next one (time of day kept). */
    public function onOrAfter(DateTimeInterface $date): DateTimeImmutable;

    /** The date itself when it is a working day, otherwise the previous one (time of day kept). */
    public function onOrBefore(DateTimeInterface $date): DateTimeImmutable;
}
