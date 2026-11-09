<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Why a Team/Directorate member's term ended (owner decision 2026-11-07).
 */
enum GrievanceUnitEndReason: string
{
    case TransferredOrganization = 'transferred_organization';
    case TransferredUnit = 'transferred_unit';
    case Retired = 'retired';
    case Dismissed = 'dismissed';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
