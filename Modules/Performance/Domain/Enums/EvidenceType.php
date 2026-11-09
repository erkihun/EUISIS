<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

enum EvidenceType: string
{
    case DailyActivity = 'DAILY_ACTIVITY';
    case Document = 'DOCUMENT';
    case SystemRecord = 'SYSTEM_RECORD';
    case ApiRecord = 'API_RECORD';
    case ManagerConfirmation = 'MANAGER_CONFIRMATION';
    case Other = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
