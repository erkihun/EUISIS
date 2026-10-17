<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Announcement priority. IMPORTANT and above notify in-app at once; URGENT and EMERGENCY may also use e-mail or SMS where the system and the reader allow it. */
enum AnnouncementPriority: string
{
    case Normal = 'NORMAL';
    case Important = 'IMPORTANT';
    case Urgent = 'URGENT';
    case Emergency = 'EMERGENCY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function notifiesImmediately(): bool
    {
        return $this !== self::Normal;
    }

    public function usesExternalChannels(): bool
    {
        return in_array($this, [self::Urgent, self::Emergency], true);
    }
}
