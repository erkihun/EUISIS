<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Coarse kind of work, used for "activity by type" reporting when an item is
 * not tied to a registered position service. A fixed list rather than a
 * lookup table: it exists to group reports, not to be administered.
 */
enum DailyActivityCategory: string
{
    case ServiceDelivery = 'service_delivery';
    case Meeting = 'meeting';
    case ReportPreparation = 'report_preparation';
    case Administrative = 'administrative';
    case Training = 'training';
    case FieldWork = 'field_work';
    case AssignedInstruction = 'assigned_instruction';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
