<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Which institution group a participant reports as (the forms' ማዕከል / ክ/ከተማ / ወረዳ). */
enum ReportingLevel: string
{
    case Center = 'center';
    case SubCity = 'sub_city';
    case Woreda = 'woreda';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.reporting_level.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Form A is for center institutions; Form B for sub-city and woreda institutions. */
    public function formType(): FormType
    {
        return $this === self::Center ? FormType::A : FormType::B;
    }
}
