<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Server-computed result of comparing a GPS capture with the expected
 * destination. This status (not the coordinates) is what ordinary viewers see.
 */
enum FieldWorkLocationValidation: string
{
    case WithinExpectedArea = 'within_expected_area';
    case OutsideExpectedArea = 'outside_expected_area';
    case LowAccuracy = 'low_accuracy';
    case CannotValidate = 'cannot_validate';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
