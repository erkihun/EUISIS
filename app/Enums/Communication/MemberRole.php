<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** A member's role in a group or channel. */
enum MemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
