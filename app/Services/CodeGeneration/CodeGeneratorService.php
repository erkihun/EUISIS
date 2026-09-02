<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Exceptions\RandomCodeGenerationException;
use App\Models\CodeGenerationLog;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CodeGeneratorService
{
    private const MAX_RANDOM_CODE_ATTEMPTS = 20;

    private const MAX_SEQUENCE_CODE_ATTEMPTS = 1000;

    public function __construct(
        private readonly CodeFormatTokenResolver $resolver,
        private readonly SequenceScopeResolver $scopeResolver,
    ) {}

    public function preview(CodeRule $rule, array $context = [], ?int $sequenceNumber = null): string
    {
        if ($sequenceNumber !== null) {
            return $this->formatCode($rule, $context, $sequenceNumber);
        }

        // Attempt to look up the current next_number for the resolved scope
        // without acquiring any lock and without creating new rows.
        $previewNumber = $this->resolvePreviewSequenceNumber($rule, $context);

        return $this->formatCode($rule, $context, $previewNumber);
    }

    public function generate(
        CodeRule $rule,
        array $context = [],
        ?User $actor = null,
        ?string $entityId = null,
        ?string $expectedGeneratedCode = null,
    ): string {
        return DB::transaction(function () use ($rule, $context, $actor, $entityId, $expectedGeneratedCode): string {
            $lockedRule = CodeRule::query()
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $scope = $this->scopeResolver->resolve($lockedRule, $context);

            $sequence = CodeRuleSequence::query()
                ->where('code_rule_id', $lockedRule->getKey())
                ->where('sequence_scope_hash', $scope['scope_hash'])
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                // Use initial_sequence_number (the admin-configured start) so that a
                // new scope (e.g. a new UNIT_TYPE_PREFIX value) always begins from the
                // intended starting point even if next_number was advanced by previous
                // global-scope generation.
                $startAt = max(1, $lockedRule->initial_sequence_number ?? $lockedRule->next_number);

                $sequence = new CodeRuleSequence([
                    'code_rule_id' => $lockedRule->getKey(),
                    'sequence_scope_key' => $scope['scope_key'],
                    'sequence_scope_hash' => $scope['scope_hash'],
                    'sequence_scope_values' => $scope['scope_values'],
                    'next_number' => $startAt,
                    'reset_frequency' => $lockedRule->reset_frequency?->value,
                ]);
            }

            $this->resetSequenceIfDue($sequence, $lockedRule);

            $sequenceNumber = max(1, $sequence->next_number);
            $usesRandomToken = str_contains($lockedRule->format, '{RAND_6}');
            $maximumAttempts = $usesRandomToken
                ? self::MAX_RANDOM_CODE_ATTEMPTS
                : self::MAX_SEQUENCE_CODE_ATTEMPTS;
            $attempts = 0;
            $generatedCode = '';

            while ($attempts < $maximumAttempts) {
                $generatedCode = $usesRandomToken && $expectedGeneratedCode !== null
                    ? $expectedGeneratedCode
                    : $this->formatCode($lockedRule, $context, $sequenceNumber);
                $attempts++;

                if (! $this->codeExists($lockedRule->entity_type, $generatedCode)) {
                    break;
                }

                if ($usesRandomToken && $expectedGeneratedCode !== null) {
                    throw new RandomCodeGenerationException('The previewed random code is no longer available.');
                }

                if (! $usesRandomToken) {
                    $sequenceNumber++;
                }
            }

            if ($this->codeExists($lockedRule->entity_type, $generatedCode)) {
                if ($usesRandomToken) {
                    throw new RandomCodeGenerationException('Unable to generate a unique random code after 20 attempts.');
                }

                throw new RuntimeException('Unable to generate a unique code after multiple attempts.');
            }

            $sequence->fill([
                'next_number' => $sequenceNumber + 1,
                'last_number' => $sequenceNumber,
                'last_generated_code' => $generatedCode,
            ]);
            $sequence->save();

            // Keep code_rules.next_number in sync whenever the resolved scope is
            // global (either because the strategy is 'global', or because auto-
            // detection found no grouping tokens and fell back to the global key).
            $isGlobalScope = $scope['scope_key'] === '_global_';

            $syncAttrs = ['updated_by' => $actor?->getKey()];

            if ($isGlobalScope) {
                $syncAttrs['next_number'] = $sequenceNumber + 1;
            }

            $lockedRule->forceFill($syncAttrs)->save();

            CodeGenerationLog::query()->create([
                'code_rule_id' => $lockedRule->getKey(),
                'entity_type' => $lockedRule->entity_type,
                'entity_id' => $entityId,
                'generated_code' => $generatedCode,
                'sequence_number' => $sequenceNumber,
                'generated_by' => $actor?->getKey(),
                'generated_at' => now(),
                'metadata' => [
                    'scope_type' => $lockedRule->scope_type,
                    'scope_id' => $lockedRule->scope_id,
                    'sequence_scope_key' => $scope['scope_key'],
                ],
            ]);

            return $generatedCode;
        });
    }

    public function formatCode(CodeRule $rule, array $context, int $sequenceNumber): string
    {
        $timestamp = $context['timestamp'] ?? now();
        $date = $timestamp instanceof Carbon ? $timestamp : Carbon::parse((string) $timestamp);

        $context['_sequence_number'] = $sequenceNumber;

        $replacements = $this->resolver->resolveAll($rule, $context, $date);

        if ($rule->year_format && $rule->year_format !== '') {
            $replacements['{YEAR}'] = $date->format($rule->year_format);
        }

        $formatted = strtr($rule->format, $replacements);

        if ($rule->separator !== null && $rule->separator !== '') {
            $sep = preg_quote($rule->separator, '/');
            $formatted = preg_replace('/(?:'.$sep.'){2,}/', $rule->separator, $formatted) ?? $formatted;
        }

        $formatted = (string) preg_replace('/[^\p{L}\p{N}\-_.\\/]/u', '', $formatted);
        $formatted = substr($formatted, 0, 100);

        return trim($formatted, ($rule->separator ?? '').' ');
    }

    /**
     * Read the current next_number for a preview without acquiring locks or
     * creating rows.
     */
    private function resolvePreviewSequenceNumber(CodeRule $rule, array $context): int
    {
        try {
            $scope = $this->scopeResolver->resolve($rule, $context);
        } catch (MissingSequenceScopeContextException) {
            return max(1, $rule->next_number);
        }

        $sequence = CodeRuleSequence::query()
            ->where('code_rule_id', $rule->getKey())
            ->where('sequence_scope_hash', $scope['scope_hash'])
            ->first();

        return $sequence !== null
            ? max(1, $sequence->next_number)
            : max(1, $rule->initial_sequence_number ?? $rule->next_number);
    }

    private function resetSequenceIfDue(CodeRuleSequence $sequence, CodeRule $rule): void
    {
        $now = now();
        $lastResetAt = $sequence->last_reset_at;

        if ($rule->reset_frequency === CodeRuleResetFrequency::Never) {
            return;
        }

        if ($lastResetAt === null) {
            $sequence->fill([
                'next_number' => 1,
                'last_reset_at' => $now,
            ]);

            return;
        }

        $shouldReset = match ($rule->reset_frequency) {
            CodeRuleResetFrequency::Yearly => $lastResetAt->year !== $now->year,
            CodeRuleResetFrequency::Monthly => $lastResetAt->format('Y-m') !== $now->format('Y-m'),
            CodeRuleResetFrequency::Daily => $lastResetAt->toDateString() !== $now->toDateString(),
            default => false,
        };

        if (! $shouldReset) {
            return;
        }

        $sequence->fill([
            'next_number' => 1,
            'last_reset_at' => $now,
        ]);
    }

    private function codeExists(CodeRuleEntityType|string $entityType, string $generatedCode): bool
    {
        $entity = $entityType instanceof CodeRuleEntityType ? $entityType : CodeRuleEntityType::from($entityType);

        return match ($entity) {
            CodeRuleEntityType::Organization => Organization::withTrashed()->where('code', $generatedCode)->exists(),
            CodeRuleEntityType::OrganizationType => OrganizationType::withTrashed()->where('code', $generatedCode)->exists(),
            CodeRuleEntityType::Employee => Employee::query()->where('employee_number', $generatedCode)->exists(),
            CodeRuleEntityType::Position, CodeRuleEntityType::EmployeePosition => Position::withTrashed()->where('job_position_code', $generatedCode)->exists(),
            CodeRuleEntityType::IdCard => IdCard::query()->where('card_number', $generatedCode)->exists(),
            CodeRuleEntityType::ServiceProvider => ServiceProvider::query()->where('code', $generatedCode)->exists(),
            CodeRuleEntityType::ServiceType => ServiceType::withTrashed()->where('code', $generatedCode)->exists(),
            CodeRuleEntityType::OrganizationUnit => OrganizationUnit::withTrashed()->where('code', $generatedCode)->exists(),
            CodeRuleEntityType::OrganizationUnitType => OrganizationUnitType::withTrashed()->where('code', $generatedCode)->exists(),
            default => false,
        };
    }
}
