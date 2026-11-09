<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** An Ethiopian fiscal year in the module. */
enum FiscalYearStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.fiscal_year_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
