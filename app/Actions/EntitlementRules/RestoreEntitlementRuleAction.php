<?php

declare(strict_types=1);

namespace App\Actions\EntitlementRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EntitlementRule;
use App\Models\User;
use Illuminate\Http\Request;

class RestoreEntitlementRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(EntitlementRule $entitlementRule, ?User $actor = null, ?Request $request = null): EntitlementRule
    {
        $oldValues = $entitlementRule->toArray();

        $entitlementRule->restore();
        $entitlementRule->forceFill([
            'is_active' => true,
            'deleted_by' => null,
            'deletion_reason' => null,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordRestored,
            $actor,
            $entitlementRule->fresh(),
            null,
            $oldValues,
            $entitlementRule->fresh()->toArray(),
            request: $request,
        );

        return $entitlementRule->fresh() ?? $entitlementRule;
    }
}
