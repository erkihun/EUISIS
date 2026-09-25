<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Calculated state of one calendar day for one employee.
 *
 * Never stored. Missing days are derived from the work calendar, leave,
 * employment and assignment dates minus the logs that exist, so the system
 * does not pre-create empty rows for every employee and every day.
 */
enum DailyActivityDayStatus: string
{
    /** A working day that is still open for today's submission. */
    case Required = 'required';
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Missing = 'missing';
    case Leave = 'leave';
    case PublicHoliday = 'public_holiday';
    case Weekend = 'weekend';
    case NotEmployed = 'not_employed';
    case NotAssigned = 'not_assigned';
    case Future = 'future';
    /** Before the configured tracking start date: not measured at all. */
    case NotTracked = 'not_tracked';

    /** Days on which the employee was expected to register activity. */
    public function isRequiredDay(): bool
    {
        return in_array($this, [self::Required, self::Draft, self::Submitted, self::Approved, self::Returned, self::Missing], true);
    }

    /** Days that are exempt and must never be reported as missing. */
    public function isExempt(): bool
    {
        return in_array($this, [self::Leave, self::PublicHoliday, self::Weekend], true);
    }
}
