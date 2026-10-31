<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * How a recipient receives a letter.
 */
enum GrievanceRecipientKind: string
{
    case To = 'to';
    case Cc = 'cc';
    case BccInternal = 'bcc_internal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
