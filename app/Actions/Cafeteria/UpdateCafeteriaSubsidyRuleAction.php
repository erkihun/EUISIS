<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSubsidyRule;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdateCafeteriaSubsidyRuleAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSubsidyRule $rule, array $attributes, User $actor, ?Request $request = null): CafeteriaSubsidyRule
    {
        $oldValues = ['subsidy_amount' => $rule->subsidy_amount, 'is_active' => $rule->is_active];

        $rule->forceFill([
            'name_en'          => trim($attributes['name_en']),
            'name_am'          => isset($attributes['name_am']) ? trim($attributes['name_am']) : $rule->name_am,
            'subsidy_amount'   => (float) $attributes['subsidy_amount'],
            'currency'         => strtoupper($attributes['currency'] ?? $rule->currency),
            'effective_from'   => $attributes['effective_from'] ?? $rule->effective_from,
            'effective_to'     => array_key_exists('effective_to', $attributes) ? $attributes['effective_to'] : $rule->effective_to,
            'applies_to'       => $attributes['applies_to'] ?? $rule->applies_to,
            'organization_id'  => $attributes['organization_id'] ?? $rule->organization_id,
            'employee_type'    => $attributes['employee_type'] ?? $rule->employee_type,
            'is_active'        => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : $rule->is_active,
            'exclude_weekends' => isset($attributes['exclude_weekends']) ? (bool) $attributes['exclude_weekends'] : $rule->exclude_weekends,
            'notes'            => $attributes['notes'] ?? $rule->notes,
            'updated_by'       => $actor->id,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSubsidyRuleUpdated,
            $actor,
            $rule,
            $rule->organization_id,
            oldValues: $oldValues,
            newValues: ['subsidy_amount' => $rule->subsidy_amount, 'is_active' => $rule->is_active],
            request: $request,
        );

        return $rule;
    }
}
