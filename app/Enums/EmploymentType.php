<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an employee is engaged — permanent, contract, and so on.
 *
 * Deliberately separate from EmployeeStatus, which tracks the lifecycle of the
 * record itself (active, suspended, retired). A permanent employee can be
 * suspended; a contract employee can be active. The two never share a column.
 */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case Temporary = 'temporary';
    case Probation = 'probation';
    case DailyLabor = 'daily_labor';
    case Intern = 'intern';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /** Translated label for display on screen and on the printed card. */
    public function label(?string $locale = null): string
    {
        return (string) __('employment_types.'.$this->value, [], $locale);
    }
}
