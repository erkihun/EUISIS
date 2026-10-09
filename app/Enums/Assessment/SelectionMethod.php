<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * How evaluators of a type are chosen. Never by the employee, never at random.
 */
enum SelectionMethod: string
{
    case System = 'system';
    case ManagerSelected = 'manager_selected';
    case AdminSelected = 'admin_selected';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
