<?php

declare(strict_types=1);

namespace App\Enums;

/** Progress of a single activity item, as reported by the employee. */
enum DailyActivityProgressStatus: string
{
    case Completed = 'completed';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case CarriedForward = 'carried_forward';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
