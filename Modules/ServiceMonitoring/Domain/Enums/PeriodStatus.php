<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A reporting period's state. Open/closed follow the schedule; locked freezes everything. */
enum PeriodStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.period_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
