<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Enums\CodeRuleEntityType;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\SequenceScopeResolver;

/**
 * Projects the codes an import *would* generate, without consuming any sequence.
 *
 * The wizard has to show the user the actual code each blank cell will receive
 * before anything is written. CodeGeneratorService::preview() answers that for a
 * single record, but it always reports the same "next number" — so previewing a
 * sheet with four blank codes would show the same code four times.
 *
 * This projector fixes that by tracking, per sequence scope, how many codes the
 * file has already consumed, and asking the generator to format the code at
 * `next_number + offset`. Sequences are scoped exactly the way the real
 * generator scopes them (same SequenceScopeResolver), so a rule whose sequence
 * resets per organization type projects independently per type, just as it will
 * at import time.
 *
 * These are projections, not reservations. Nothing is locked and no number is
 * burned, so a preview costs nothing and can be repeated. The confirm step
 * re-runs the real generator under a lock and compares — see
 * {@see OrganizationStructureImportService::confirm()}.
 */
class CodeProjector
{
    /** @var array<string, int> scope hash => codes already projected for it */
    private array $consumed = [];

    /** @var array<string, int> row projection key => reserved sequence offset */
    private array $rowOffsets = [];

    /** @var array<string, CodeRule|null> entity type => resolved rule (null = none configured) */
    private array $ruleCache = [];

    public function __construct(
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeGeneratorService $codeGeneratorService,
        private readonly SequenceScopeResolver $sequenceScopeResolver,
        private readonly array $lockedRandomCodes = [],
    ) {}

    /**
     * The code rule that governs an entity type in a given context, or null when
     * none is configured. Cached per entity type because a single import asks
     * this once per row.
     */
    public function rule(CodeRuleEntityType $entityType, array $context = []): ?CodeRule
    {
        $key = $entityType->value;

        if (! array_key_exists($key, $this->ruleCache)) {
            $this->ruleCache[$key] = $this->codeRuleResolver->resolve($entityType, $context);
        }

        return $this->ruleCache[$key];
    }

    /**
     * Project the next code for an entity, advancing this projector's per-scope
     * counter so the following row in the same scope gets the next number.
     *
     * Returns null when no code rule is configured — the caller turns that into
     * a row-level "Code rule is not configured" error rather than throwing.
     */
    public function project(CodeRuleEntityType $entityType, array $context = [], ?string $lockKey = null): ?string
    {
        $rule = $this->rule($entityType, $context);

        if ($rule === null) {
            return null;
        }

        if ($lockKey !== null
            && str_contains($rule->format, '{RAND_6}')
            && isset($this->lockedRandomCodes[$lockKey])) {
            return (string) $this->lockedRandomCodes[$lockKey];
        }

        $scope = $this->resolveScope($rule, $context);
        $scopeHash = $rule->getKey().':'.($scope['scope_hash'] ?? 'global');

        $rowOffsetKey = $lockKey !== null ? $scopeHash.':'.$lockKey : null;

        if ($rowOffsetKey !== null && array_key_exists($rowOffsetKey, $this->rowOffsets)) {
            $offset = $this->rowOffsets[$rowOffsetKey];
        } else {
            $offset = $this->consumed[$scopeHash] ?? 0;
            $this->consumed[$scopeHash] = $offset + 1;

            if ($rowOffsetKey !== null) {
                $this->rowOffsets[$rowOffsetKey] = $offset;
            }
        }

        // The sequence number the next real generate() would use, plus however
        // many codes this file has already claimed in the same scope.
        $nextNumber = $this->nextSequenceNumber($rule, $scope);

        return $this->codeGeneratorService->formatCode($rule, $context, $nextNumber + $offset);
    }

    /**
     * The sequence number the real generator would start from for this scope —
     * resolved exactly as CodeGeneratorService does, so a projection lands on
     * the same number the import will actually take.
     *
     * @param  array{scope_hash: string}|null  $scope
     */
    private function nextSequenceNumber(CodeRule $rule, ?array $scope): int
    {
        $sequence = $scope === null
            ? null
            : CodeRuleSequence::query()
                ->where('code_rule_id', $rule->getKey())
                ->where('sequence_scope_hash', $scope['scope_hash'])
                ->first();

        return $sequence !== null
            ? max(1, $sequence->next_number)
            : max(1, $rule->initial_sequence_number ?? $rule->next_number);
    }

    /** @return array{scope_hash: string}|null */
    private function resolveScope(CodeRule $rule, array $context): ?array
    {
        try {
            return $this->sequenceScopeResolver->resolve($rule, $context);
        } catch (MissingSequenceScopeContextException) {
            // The context cannot resolve the rule's scope tokens. The real
            // generator will raise the same problem; the caller reports it as a
            // row error via project() returning a code that fails validation.
            return null;
        }
    }
}
