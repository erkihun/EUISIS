<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaReportType: string
{
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match($this) {
            self::Daily   => 'Daily Report',
            self::Weekly  => 'Weekly Report',
            self::Monthly => 'Monthly Report',
        };
    }
}
