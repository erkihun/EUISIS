<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of disability an employee chooses to declare, for reporting the
 * employment of persons with disabilities. Sensitive personal data: shown to
 * those who may view the employee, counted in aggregate statistics, never sent
 * through the master-data API.
 */
enum DisabilityType: string
{
    case Physical = 'physical';
    case Visual = 'visual';
    case Hearing = 'hearing';
    case Intellectual = 'intellectual';
    case Psychosocial = 'psychosocial';
    case Multiple = 'multiple';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
