<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Models\CodeRule;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

/** EUISIS ArchiveCodeRuleAction over the Core's audit trail: the rule goes to the recycle bin and stops applying. */
class ArchiveCodeRuleAction
{
    public function __construct(private readonly AuditWriter $audit) {}

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

        $this->audit->writeFor('code_rule.archived', $actor, 'CodeRule', (string) $codeRule->getKey(), null, $oldValues,
            ['deleted_at' => now()->toISOString(), 'deleted_by' => $actor?->getKey(), 'deletion_reason' => $reason], $reason);
    }
}
