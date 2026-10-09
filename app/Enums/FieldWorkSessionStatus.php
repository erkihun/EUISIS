<?php

declare(strict_types=1);

namespace App\Enums;

/** Operational state for one participant; separate from the request workflow. */
enum FieldWorkSessionStatus: string
{
    case ApprovedNotCheckedIn = 'approved_not_checked_in';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case GpsBlocked = 'gps_blocked';
}
