<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Enums;

/** Why a line was written to an employee's leave ledger. */
enum LeaveEntryType: string
{
    // The yearly entitlement of a budget year.
    case Grant = 'grant';
    // Days an employee already had when the institution started using the module.
    case Opening = 'opening';
    // Days taken by an approved request.
    case Taken = 'taken';
    // Days given back when an approved leave is cancelled or cut short.
    case Returned = 'returned';
    // A correction by an HR officer, with its reason.
    case Adjustment = 'adjustment';
    // The head of the institution allowed a year's unused days to be used later (zero days; sets usable_until_year).
    case Postponement = 'postponement';
    // Unused days that passed the last year in which they could be used.
    case Expired = 'expired';
    // Pre-natal days beyond the expected date, taken from annual leave.
    case MaternityOverrun = 'maternity_overrun';
}
