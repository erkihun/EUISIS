<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CodeRule;
use App\Models\User;
use Illuminate\Http\Request;

class ArchiveCodeRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(CodeRule $codeRule, ?User $actor = null, ?string $reason = null, ?Request $request = null): void
    {
        $oldValues = $codeRule->toArray();

        $codeRule->forceFill([
            'is_active' => false,
            'active_scope_key' => null,
            'updated_by' => $actor?->getKey(),
            'deleted_by' => $actor?->getKey(),
            'deletion_reason' => $reason,
        ])->save();

        $codeRule->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::RecordDeleted,
            $actor,
            $codeRule,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString(), 'deleted_by' => $actor?->getKey(), 'deletion_reason' => $reason],
            $reason,
            $request,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::CodeRuleArchived,
            $actor,
            $codeRule,
            null,
            $oldValues,
            ['deleted_at' => now()->toISOString()],
            $reason,
            $request,
        );
    }
}
