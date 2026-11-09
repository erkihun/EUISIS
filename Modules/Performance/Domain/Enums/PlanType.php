<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

/**
 * Level of a performance plan. The employee level is the EmployeePerformanceAgreement.
 */
enum PlanType: string
{
    case Organization = 'ORGANIZATION';
    case Unit = 'UNIT';
    case Position = 'POSITION';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
