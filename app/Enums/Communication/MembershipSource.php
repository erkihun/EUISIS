<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** How a member came to be in a conversation. AUTO memberships follow the organizational assignment; MANUAL and SYSTEM ones are never removed by a transfer sync unless the channel says so. */
enum MembershipSource: string
{
    case Manual = 'MANUAL';
    case Auto = 'AUTO';
    case System = 'SYSTEM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
