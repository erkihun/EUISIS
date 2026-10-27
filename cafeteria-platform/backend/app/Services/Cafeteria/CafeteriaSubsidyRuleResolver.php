<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaSubsidyRule;
use App\Models\Employee;
use Illuminate\Support\Carbon;

class CafeteriaSubsidyRuleResolver
{
    /**
     * Resolve the active subsidy rule for an employee on a given date.
     * Preference order: employee-org-specific → all_employees default.
     */
    public function resolve(Employee $employee, Carbon $date): ?CafeteriaSubsidyRule
    {
        $orgId = $employee->currentAssignment?->organization_id;

        // Try org-specific rule first
        if ($orgId) {
            $rule = CafeteriaSubsidyRule::query()
                ->where('is_active', true)
                ->where('applies_to', 'organization')
                ->where('organization_id', $orgId)
                ->where('effective_from', '<=', $date->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString()))
                ->whereNull('deleted_at')
                ->orderByDesc('effective_from')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        // Fall back to global rule
        return CafeteriaSubsidyRule::query()
            ->where('is_active', true)
            ->where('applies_to', 'all_employees')
            ->where('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString()))
            ->whereNull('deleted_at')
            ->orderByDesc('effective_from')
            ->first();
    }
}
