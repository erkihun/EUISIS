<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Who may see a timeline event. Complainant events carry safe progress only.
 */
enum GrievanceEventVisibility: string
{
    case Complainant = 'complainant';
    case Internal = 'internal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
