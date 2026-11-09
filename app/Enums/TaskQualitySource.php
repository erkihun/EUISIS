<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who records the actual quality of a task: the employee doing the work, or
 * the reviewer when the work is checked. Set per standard, because the
 * approved standard decides who is the authority on quality.
 */
enum TaskQualitySource: string
{
    case Employee = 'employee';
    case Reviewer = 'reviewer';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
