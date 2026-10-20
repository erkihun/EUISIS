<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** A parsed mention: one person, everyone currently in the channel, or everyone. */
enum MentionType: string
{
    case User = 'USER';
    case Channel = 'CHANNEL';
    case Everyone = 'EVERYONE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
