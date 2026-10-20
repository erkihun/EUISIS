<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 * How a contract turns verified usage into an amount. The model names the
 * formula; the rates come from the contract's approved terms, never from
 * code. A model whose terms are missing yields no amount and a
 * NEEDS_POLICY_DECISION marker on the statement.
 */
enum TransportSettlementModel: string
{
    case PerVerifiedBoarding = 'per_verified_boarding';
    case PerEmployeePerDay = 'per_employee_per_day';
    case PerCompletedTrip = 'per_completed_trip';
    case FixedPeriodic = 'fixed_periodic';
    case FixedPlusUsage = 'fixed_plus_usage';
    case MinimumGuaranteePlusUsage = 'minimum_guarantee_plus_usage';
    case CapacityBased = 'capacity_based';
    case OtherApprovedModel = 'other_approved_model';

    /** @return list<string> the term keys this model reads */
    public function requiredTerms(): array
    {
        return match ($this) {
            self::PerVerifiedBoarding => ['rate_per_boarding'],
            self::PerEmployeePerDay => ['rate_per_employee_day'],
            self::PerCompletedTrip => ['rate_per_trip'],
            self::FixedPeriodic => ['fixed_amount_per_period'],
            self::FixedPlusUsage => ['fixed_amount_per_period', 'rate_per_boarding'],
            self::MinimumGuaranteePlusUsage => ['minimum_guarantee_amount', 'rate_per_boarding'],
            self::CapacityBased => ['rate_per_seat_trip'],
            self::OtherApprovedModel => [],
        };
    }

    /** @return list<string> every term key any model may carry */
    public static function allTerms(): array
    {
        return ['rate_per_boarding', 'rate_per_employee_day', 'rate_per_trip', 'fixed_amount_per_period', 'minimum_guarantee_amount', 'rate_per_seat_trip'];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
