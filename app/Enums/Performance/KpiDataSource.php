<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum KpiDataSource: string
{
    case Manual = 'MANUAL';
    case DailyActivity = 'DAILY_ACTIVITY';
    case SystemTransaction = 'SYSTEM_TRANSACTION';
    case Api = 'API';
    case Survey = 'SURVEY';
    case DocumentEvidence = 'DOCUMENT_EVIDENCE';
    case Formula = 'FORMULA';
    case ExternalSystem = 'EXTERNAL_SYSTEM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
