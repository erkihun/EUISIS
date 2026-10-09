<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a scan does once the day's entitlement is already used.
 *
 *  block                 — refused (EUISIS baseline: one meal a day)
 *  deduct_next_available — consumes the next unused entitlement day in the
 *                          current window (needs advance usage allowed)
 *  employee_paid         — served, the employee pays the full provider price,
 *                          no entitlement is consumed
 */
enum CafeteriaExtraScanPolicy: string
{
    case Block = 'block';
    case DeductNextAvailable = 'deduct_next_available';
    case EmployeePaid = 'employee_paid';
}
