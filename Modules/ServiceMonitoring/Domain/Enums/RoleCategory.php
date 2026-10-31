<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Section I "ተጠያቂ የተደረገው ስራ ድርሻ" (D17). */
enum RoleCategory: string
{
    case AppointeeLeader = 'appointee_leader';
    case MeritLeader = 'merit_leader';
    case Professional = 'professional';
    case Customer = 'customer';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.role_category.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
