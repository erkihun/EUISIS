<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Derived at read time from the schedule and the recorded events, never
 * stored, so no job has to keep it fresh and no return time is invented.
 *
 *   CHECK_IN_MISSING  approved, the start has passed, nobody has checked in
 *   OVERDUE           approved / in field, the expected return has passed,
 *                     and the work is not completed (the "not closed" case)
 */
enum FieldWorkMonitoringFlag: string
{
    case CheckInMissing = 'check_in_missing';
    case Overdue = 'overdue';
}
