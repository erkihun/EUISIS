<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** The two official forms: A (center institutions) and B (sub-city and woreda institutions). */
enum FormType: string
{
    case A = 'A';
    case B = 'B';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.form_type.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
