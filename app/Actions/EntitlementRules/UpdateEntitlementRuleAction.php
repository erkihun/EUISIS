<?php

declare(strict_types=1);

namespace App\Actions\EntitlementRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EntitlementRule;
use App\Models\User;

class UpdateEntitlementRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(EntitlementRule $entitlementRule, array $attributes, ?User $actor = null): EntitlementRule
    {
        $oldValues = $entitlementRule->only(['name', 'service_type_id', 'rule_definition', 'is_active']);

        $entitlementRule->fill($attributes)->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::EntitlementRuleUpdated,
            $actor,
            $entitlementRule,
            null,
            $oldValues,
            $entitlementRule->only(['name', 'service_type_id', 'rule_definition', 'is_active']),
        );

        return $entitlementRule;
    }
}
