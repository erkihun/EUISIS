<?php

declare(strict_types=1);

namespace Modules\Entitlements\Application\Actions\EntitlementRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Entitlements\Domain\Models\EntitlementRule;

class CreateEntitlementRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $attributes, ?User $actor = null): EntitlementRule
    {
        $rule = EntitlementRule::query()->create($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::EntitlementRuleCreated,
            $actor,
            $rule,
            null,
            null,
            ['name' => $rule->name, 'service_type_id' => $rule->service_type_id, 'is_active' => $rule->is_active],
        );

        return $rule;
    }
}
