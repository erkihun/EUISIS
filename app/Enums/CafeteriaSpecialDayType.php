<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaSpecialDayType: string
{
    case OpenDay          = 'open_day';
    case ClosedDay        = 'closed_day';
    case SubsidyDay       = 'subsidy_day';
    case NoSubsidyDay     = 'no_subsidy_day';
    case ProviderOnly     = 'provider_only';
    case EmergencyClosure = 'emergency_closure';

    public function label(): string
    {
        return match ($this) {
            self::OpenDay          => __('cafeteria.specialDayTypeOpenDay'),
            self::ClosedDay        => __('cafeteria.specialDayTypeClosedDay'),
            self::SubsidyDay       => __('cafeteria.specialDayTypeSubsidyDay'),
            self::NoSubsidyDay     => __('cafeteria.specialDayTypeNoSubsidyDay'),
            self::ProviderOnly     => __('cafeteria.specialDayTypeProviderOnly'),
            self::EmergencyClosure => __('cafeteria.specialDayTypeEmergencyClosure'),
        };
    }
}
