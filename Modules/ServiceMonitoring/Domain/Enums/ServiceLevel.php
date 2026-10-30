<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Main service or sub service (Sections III and IV). */
enum ServiceLevel: string
{
    case Main = 'main';
    case Sub = 'sub';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.service_level.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
