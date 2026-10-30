<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Innovation & Technology Bureau verification of a digital service (D20). Only verified entries count. */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.verification_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
