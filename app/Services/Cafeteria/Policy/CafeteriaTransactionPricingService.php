<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Models\CafeteriaServicePolicy;

/**
 * Prices a transaction from the resolved policy — never from request input.
 *
 * Per entitlement consumed, the provider is owed `provider_price`; the
 * employee organization pays `daily_subsidy_amount` and the employee pays the
 * rest (the configured `employee_contribution_amount`; the policy form keeps
 * price = subsidy + contribution). An employee-paid scan consumes no
 * entitlement: the employee pays the full provider price and the organization
 * nothing.
 */
class CafeteriaTransactionPricingService
{
    public function price(CafeteriaServicePolicy $policy, int $entitlementCount, bool $employeePaid = false): CafeteriaPricing
    {
        $unitPrice = CafeteriaPricing::toCents((string) $policy->provider_price);

        if ($employeePaid) {
            return new CafeteriaPricing(0, 0, $unitPrice, $unitPrice, $policy->currency_code, true);
        }

        $subsidy = CafeteriaPricing::toCents((string) $policy->daily_subsidy_amount) * $entitlementCount;
        $total = $unitPrice * $entitlementCount;

        return new CafeteriaPricing(
            $entitlementCount,
            $subsidy,
            max(0, $total - $subsidy),
            $unitPrice,
            $policy->currency_code,
            false,
        );
    }
}
