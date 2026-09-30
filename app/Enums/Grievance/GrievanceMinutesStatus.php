<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Minutes lifecycle. Confirmed minutes change only through a new version.
 */
enum GrievanceMinutesStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Superseded = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
