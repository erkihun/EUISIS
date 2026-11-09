<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CodeRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateCodeRuleAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $attributes, ?User $actor = null): CodeRule
    {
        $this->ensureNoActiveConflict($attributes);

        $codeRule = CodeRule::query()->create([
            ...$attributes,
            'initial_sequence_number' => $attributes['next_number'] ?? 1,
            'active_scope_key' => ($attributes['is_active'] ?? true)
                ? CodeRule::buildActiveScopeKey($attributes['entity_type'], $attributes['scope_type'] ?? null, $attributes['scope_id'] ?? null)
                : null,
            'created_by' => $actor?->getKey(),
            'updated_by' => $actor?->getKey(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CodeRuleCreated,
            $actor,
            $codeRule,
            null,
            null,
            $codeRule->only([
                'entity_type',
                'scope_type',
                'scope_id',
                'name_en',
                'prefix',
                'format',
                'sequence_length',
                'next_number',
                'reset_frequency',
                'is_active',
            ]),
        );

        return $codeRule;
    }

    private function ensureNoActiveConflict(array $attributes): void
    {
        if (! ($attributes['is_active'] ?? true)) {
            return;
        }

        $exists = CodeRule::query()
            ->where('entity_type', $attributes['entity_type'])
            ->where('scope_type', $attributes['scope_type'] ?? null)
            ->where('scope_id', $attributes['scope_id'] ?? null)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'entity_type' => __('code-rules.duplicate_active_rule'),
            ]);
        }
    }
}
