<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Section III "የቴክኖሎጂው አይነት" (D20). */
enum TechnologyType: string
{
    case WebSystem = 'web_system';
    case MobileApp = 'mobile_app';
    case UssdSms = 'ussd_sms';
    case Kiosk = 'kiosk';
    case ApiIntegration = 'api_integration';
    case Other = 'other';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.technology_type.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
