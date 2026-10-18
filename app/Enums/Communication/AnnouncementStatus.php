<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Announcement lifecycle. */
enum AnnouncementStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Published = 'PUBLISHED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }
}
