<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Models\CodeRule;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Validation\ValidationException;

/** EUISIS UpdateCodeRuleAction over the Core's audit trail. */
class UpdateCodeRuleAction
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @param array<string, mixed> $attributes */
    public function execute(CodeRule $codeRule, array $attributes, ?User $actor = null): CodeRule
    {
        $oldValues = $codeRule->toArray();
        $this->ensureNoActiveConflict($codeRule, $attributes);

        $fillAttributes = $attributes;
        // When the administrator changes next_number (the configured start), new per-scope sequences start there too.
        if (array_key_exists('next_number', $attributes)) {
            $fillAttributes['initial_sequence_number'] = $attributes['next_number'];
        }

        $codeRule->fill([
            ...$fillAttributes,
            'active_scope_key' => ($attributes['is_active'] ?? $codeRule->is_active)
                ? CodeRule::buildActiveScopeKey(
                    $attributes['entity_type'] ?? $codeRule->entity_type,
                    $attributes['scope_type'] ?? $codeRule->scope_type,
                    $attributes['scope_id'] ?? $codeRule->scope_id,
                )
                : null,
            'updated_by' => $actor?->getKey(),
        ])->save();

        $fresh = $codeRule->fresh();
        $this->audit->writeFor('code_rule.updated', $actor, 'CodeRule', (string) $codeRule->getKey(), null, $oldValues, $fresh->toArray());

        return $fresh;
    }

    /** @param array<string, mixed> $attributes */
    private function ensureNoActiveConflict(CodeRule $codeRule, array $attributes): void
    {
        if (! ($attributes['is_active'] ?? $codeRule->is_active)) {
            return;
        }
        $exists = CodeRule::query()
            ->whereKeyNot($codeRule->getKey())
            ->where('entity_type', $attributes['entity_type'] ?? $codeRule->entity_type)
            ->where('scope_type', $attributes['scope_type'] ?? $codeRule->scope_type)
            ->where('scope_id', $attributes['scope_id'] ?? $codeRule->scope_id)
            ->where('is_active', true)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['entity_type' => __('code-rules.duplicate_active_rule')]);
        }
    }
}
