<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSubsidyRule;
use App\Models\User;
use Illuminate\Http\Request;

readonly class CreateCafeteriaSubsidyRuleAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, User $actor, ?Request $request = null): CafeteriaSubsidyRule
    {
        $rule = CafeteriaSubsidyRule::query()->create([
            'code'             => strtoupper(trim($attributes['code'])),
            'name_en'          => trim($attributes['name_en']),
            'name_am'          => isset($attributes['name_am']) ? trim($attributes['name_am']) : null,
            'subsidy_amount'   => (float) $attributes['subsidy_amount'],
            'currency'         => strtoupper($attributes['currency'] ?? 'ETB'),
            'effective_from'   => $attributes['effective_from'],
            'effective_to'     => $attributes['effective_to'] ?? null,
            'applies_to'       => $attributes['applies_to'] ?? 'all_employees',
            'organization_id'  => $attributes['organization_id'] ?? null,
            'employee_type'    => $attributes['employee_type'] ?? null,
            'is_active'        => (bool) ($attributes['is_active'] ?? true),
            'exclude_weekends' => (bool) ($attributes['exclude_weekends'] ?? true),
            'notes'            => $attributes['notes'] ?? null,
            'created_by'       => $actor->id,
            'updated_by'       => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSubsidyRuleCreated,
            $actor,
            $rule,
            $rule->organization_id,
            newValues: ['code' => $rule->code, 'subsidy_amount' => $rule->subsidy_amount, 'effective_from' => $rule->effective_from],
            request: $request,
        );

        return $rule;
    }
}
