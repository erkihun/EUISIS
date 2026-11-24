<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/** What happens to a deadline that falls on a weekend or public holiday. */
enum HolidayAdjustment: string
{
    case None = 'none';
    case NextWorkingDay = 'next_working_day';
    case PreviousWorkingDay = 'previous_working_day';
}
