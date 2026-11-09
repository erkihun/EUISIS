<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Enums;

enum CafeteriaReportType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return (string) __('enums.cafeteria_report_type.'.$this->value);
    }
}
