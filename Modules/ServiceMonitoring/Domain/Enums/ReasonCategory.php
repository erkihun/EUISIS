<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Why someone was held accountable: delay, theft/corruption or malpractice (D13). */
enum ReasonCategory: string
{
    case ServiceDelay = 'service_delay';
    case TheftCorruption = 'theft_corruption';
    case Malpractice = 'malpractice';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.reason_category.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
