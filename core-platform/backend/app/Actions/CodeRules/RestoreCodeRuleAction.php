<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Models\CodeRule;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** EUISIS RestoreCodeRuleAction over the Core's audit trail. */
class RestoreCodeRuleAction
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function execute(CodeRule $codeRule, ?User $actor = null, ?Request $request = null): void
    {
        $exists = CodeRule::query()
            ->whereKeyNot($codeRule->getKey())
            ->where('entity_type', $codeRule->entity_type)
            ->where('scope_type', $codeRule->scope_type)
            ->where('scope_id', $codeRule->scope_id)
            ->where('is_active', true)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['entity_type' => __('code-rules.duplicate_active_rule')]);
        }

        $oldValues = $codeRule->toArray();
        $codeRule->restore();
        $codeRule->forceFill([
            'is_active' => true,
            'active_scope_key' => CodeRule::buildActiveScopeKey($codeRule->entity_type, $codeRule->scope_type, $codeRule->scope_id),
            'updated_by' => $actor?->getKey(),
            'deleted_by' => null,
            'deletion_reason' => null,
        ])->save();

        $this->audit->writeFor('code_rule.restored', $actor, 'CodeRule', (string) $codeRule->getKey(), null, $oldValues, $codeRule->fresh()->toArray());
    }
}
