<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Why an immutable consolidation snapshot was stored (D24). */
enum SnapshotKind: string
{
    case SubCityCertification = 'subcity_certification';
    case PeriodLock = 'period_lock';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.snapshot_kind.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
