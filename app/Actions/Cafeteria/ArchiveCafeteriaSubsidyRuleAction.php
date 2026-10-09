<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSubsidyRule;
use App\Models\User;
use Illuminate\Http\Request;

readonly class ArchiveCafeteriaSubsidyRuleAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSubsidyRule $rule, User $actor, ?string $reason = null, ?Request $request = null): CafeteriaSubsidyRule
    {
        $rule->forceFill([
            'is_active'       => false,
            'deleted_by'      => $actor->id,
            'deletion_reason' => $reason,
        ])->save();

        $rule->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSubsidyRuleArchived,
            $actor,
            $rule,
            $rule->organization_id,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $rule;
    }
}
