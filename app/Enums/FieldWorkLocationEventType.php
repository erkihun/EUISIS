<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * GPS lifecycle captures. Event-based only: there is no periodic or
 * background tracking type, and adding one needs an approved policy
 * (docs/field-work-security.md §GPS privacy).
 */
enum FieldWorkLocationEventType: string
{
    case FieldCheckIn = 'field_check_in';
    case FieldCheckOut = 'field_check_out';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
