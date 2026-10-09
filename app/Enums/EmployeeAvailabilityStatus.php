<?php

declare(strict_types=1);

namespace App\Enums;

/** Only statuses substantiated by installed EUISIS modules belong here. */
enum EmployeeAvailabilityStatus: string
{
    case OfficialFieldWork = 'official_field_work';
    case ApprovedNotCheckedIn = 'approved_not_checked_in';
    case Unknown = 'unknown';
}
