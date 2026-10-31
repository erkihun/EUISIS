<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Who a Section I row is about: an employee (linked record) or a customer (free text). */
enum SubjectType: string
{
    case Employee = 'employee';
    case Customer = 'customer';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.subject_type.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
