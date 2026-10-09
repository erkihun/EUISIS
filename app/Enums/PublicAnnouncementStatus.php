<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a general public-site announcement.
 *
 * `Scheduled` is stored explicitly so the admin list can show intent, but
 * public visibility never depends on it alone: an announcement is public only
 * when it is Published or Scheduled AND its `published_at` has passed AND it
 * has not expired. See PublicAnnouncement::scopeVisible(). No scheduler job is
 * needed for a scheduled item to go live.
 */
enum PublicAnnouncementStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /** Statuses whose items may be public once their date window allows. */
    public static function publicStatuses(): array
    {
        return [self::Published->value, self::Scheduled->value];
    }

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
