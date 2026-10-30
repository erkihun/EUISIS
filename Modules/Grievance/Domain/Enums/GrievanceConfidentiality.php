<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Confidentiality of a case. Every level is confidential; higher levels narrow
 * who may see details.
 */
enum GrievanceConfidentiality: string
{
    case NormalConfidential = 'normal_confidential';
    case Restricted = 'restricted';
    case HighlyRestricted = 'highly_restricted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
