<?php

declare(strict_types=1);

namespace App\Enums;

/** Who a service is for. */
enum ServiceBeneficiaryType: string
{
    case Citizen = 'citizen';
    case Business = 'business';
    case Government = 'government';
    case Employee = 'employee';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
