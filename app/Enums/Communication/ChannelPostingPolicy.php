<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Who may post in a channel. */
enum ChannelPostingPolicy: string
{
    case AllMembers = 'ALL_MEMBERS';
    case AdminsOnly = 'ADMINS_ONLY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
