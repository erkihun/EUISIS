<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CodeGenerationLog;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCodeRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(CodeRule $codeRule, array $attributes, ?User $actor = null): CodeRule
    {
        $oldValues = $codeRule->toArray();

        $this->ensureNoActiveConflict($codeRule, $attributes);
        $this->ensureEntityTypeKept($codeRule, $attributes);

        $fillAttributes = $attributes;

        // The edit form sends next_number back on every save, so only a value
        // that differs from the stored one is a change. A change moves the live
        // global counter (codes are taken from the sequence row, not from this
        // column) and becomes the start for new per-scope sequences and resets;
        // an unchanged value leaves both alone.
        $nextNumberChanged = array_key_exists('next_number', $attributes)
            && (int) $attributes['next_number'] !== (int) $codeRule->next_number;
        $globalSequence = null;

        if ($nextNumberChanged) {
            $globalSequence = CodeRuleSequence::query()
                ->where('code_rule_id', $codeRule->getKey())
                ->where('sequence_scope_key', '_global_')
                ->first();

            if ($globalSequence !== null && (int) $attributes['next_number'] < (int) $globalSequence->next_number) {
                throw ValidationException::withMessages([
                    'next_number' => __('code-rules.next_number_below_reached', [
                        'number' => $globalSequence->next_number,
                        'last' => $globalSequence->next_number - 1,
                    ]),
                ]);
            }

            $fillAttributes['initial_sequence_number'] = $attributes['next_number'];
        } else {
            unset($fillAttributes['next_number']);
        }

        DB::transaction(function () use ($globalSequence, $attributes, $codeRule, $fillAttributes, $actor): void {
            $globalSequence?->update(['next_number' => (int) $attributes['next_number']]);

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
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::CodeRuleUpdated,
            $actor,
            $codeRule->fresh(),
            null,
            $oldValues,
            $codeRule->fresh()->toArray(),
        );

        return $codeRule->fresh();
    }

    /**
     * Codes a rule has issued belong to one record type; pointing the rule at
     * another type would carry its counters and history over to records it
     * never numbered.
     */
    private function ensureEntityTypeKept(CodeRule $codeRule, array $attributes): void
    {
        $current = $codeRule->entity_type instanceof BackedEnum ? $codeRule->entity_type->value : (string) $codeRule->entity_type;
        $requested = $attributes['entity_type'] ?? $current;
        $requested = $requested instanceof BackedEnum ? $requested->value : (string) $requested;

        if ($requested === $current) {
            return;
        }

        $hasIssued = CodeGenerationLog::query()->where('code_rule_id', $codeRule->getKey())->exists()
            || CodeRuleSequence::query()->where('code_rule_id', $codeRule->getKey())->exists();

        if ($hasIssued) {
            throw ValidationException::withMessages([
                'entity_type' => __('code-rules.entity_type_locked'),
            ]);
        }
    }

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
            throw ValidationException::withMessages([
                'entity_type' => __('code-rules.duplicate_active_rule'),
            ]);
        }
    }
}
