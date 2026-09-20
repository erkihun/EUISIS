<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a public-site service listing.
 *
 * Deliberately separate from internal `ServiceType` / `PositionService`, which
 * describe the tasks an employee performs. These are what the public reads.
 *
 * `Hidden` is a temporary pull (e.g. a service suspended for a week) and
 * `Archived` is retirement; neither is visible publicly.
 */
enum PublicServiceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
    case Archived = 'archived';

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
