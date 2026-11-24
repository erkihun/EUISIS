<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/** The calendar a schedule recurs on. It is a property of the schedule, never of the interface language. */
enum CalendarBasis: string
{
    case Ethiopian = 'ethiopian';
    case Gregorian = 'gregorian';
}
