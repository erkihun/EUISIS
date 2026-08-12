<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Models\CodeRule;
use App\Models\User;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use Illuminate\Validation\ValidationException;

class GenerateCodeAction
{
    public function __construct(
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeGeneratorService $codeGeneratorService,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(
        CodeRuleEntityType|string $entityType,
        array $context = [],
        ?User $actor = null,
        ?string $manualCode = null,
        string $field = 'code',
        ?string $entityId = null,
        ?CodeRule $resolvedRule = null,
    ): string {
        $codeRule = $resolvedRule ?? $this->codeRuleResolver->resolve($entityType, $context);

        if ($manualCode !== null && trim($manualCode) !== '') {
            if ($codeRule !== null) {
                if (! $codeRule->allow_manual_override) {
                    throw ValidationException::withMessages([
                        $field => __('code-rules.manual_override_not_allowed'),
                    ]);
                }

                if ($codeRule->require_approval_for_override && ! ($actor?->can('code-rules.manageOverrides') ?? false)) {
                    throw ValidationException::withMessages([
                        $field => __('code-rules.manual_override_not_allowed'),
                    ]);
                }

                $this->writeAuditLogAction->execute(
                    AuditEventType::CodeManualOverrideUsed,
                    $actor,
                    $codeRule,
                    null,
                    null,
                    [
                        'entity_type' => $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType,
                        'code' => $manualCode,
                        'field' => $field,
                    ],
                );
            }

            return trim($manualCode);
        }

        if ($codeRule === null) {
            throw ValidationException::withMessages([
                $field => __('code-rules.no_active_rule'),
            ]);
        }

        try {
            $generatedCode = $this->codeGeneratorService->generate($codeRule, $context, $actor, $entityId);
        } catch (MissingSequenceScopeContextException $exception) {
            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }

        $this->writeAuditLogAction->execute(
            AuditEventType::CodeGenerated,
            $actor,
            $codeRule,
            null,
            null,
            [
                'entity_type' => $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType,
                'generated_code' => $generatedCode,
                'field' => $field,
            ],
        );

        return $generatedCode;
    }
}
