<?php

declare(strict_types=1);

namespace App\Enums;

/** Why an organization is being designed (brief §112). */
enum DesignStudyType: string
{
    case InitialStructure = 'initial_structure';
    case Restructure = 'restructure';
    case Review = 'review';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
