<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * What an SLA profile times.
 */
enum GrievanceSlaPurpose: string
{
    case Resolution = 'resolution';
    case Approval = 'approval';
    case AppealFiling = 'appeal_filing';
    case InformationResponse = 'information_response';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
