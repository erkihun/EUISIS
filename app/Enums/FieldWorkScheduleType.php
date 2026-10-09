<?php

declare(strict_types=1);

namespace App\Enums;

/** Derived server-side from the start and expected return; never chosen by the browser. */
enum FieldWorkScheduleType: string
{
    case PartialDay = 'partial_day';
    case FullDay = 'full_day';
    case MultiDay = 'multi_day';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
