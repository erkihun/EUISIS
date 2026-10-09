<?php

declare(strict_types=1);

namespace App\Actions\EntitlementRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\EntitlementRule;
use App\Models\User;
use Illuminate\Http\Request;

class ArchiveEntitlementRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(EntitlementRule $entitlementRule, ?User $actor = null, ?string $reason = null, ?Request $request = null): EntitlementRule
    {
        $oldValues = $entitlementRule->toArray();

        $entitlementRule->forceFill([
            'is_active' => false,
            'deleted_by' => $actor?->getKey(),
            'deletion_reason' => $reason,
        ])->save();

        $entitlementRule->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $entitlementRule,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString(), 'deleted_by' => $actor?->getKey(), 'deletion_reason' => $reason],
            $reason,
            $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::EntitlementRuleArchived,
            $actor,
            $entitlementRule,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString()],
            $reason,
            $request,
        );

        return $entitlementRule;
    }
}
