<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A correction request against an accepted submission (D9). */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.correction_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
