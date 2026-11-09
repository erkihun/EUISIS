<?php

declare(strict_types=1);

namespace App\Actions\CodeRules;

use App\Enums\CodeRuleScopeType;
use App\Models\CodeRule;
use App\Services\CodeGeneration\CodeGeneratorService;

class PreviewCodeRuleAction
{
    public function __construct(
        private readonly CodeGeneratorService $codeGeneratorService,
    ) {}

    public function execute(array $attributes, array $context = []): string
    {
        $rule = new CodeRule($attributes);
        $rule->next_number = (int) ($attributes['next_number'] ?? 1);
        $rule->sequence_length = (int) ($attributes['sequence_length'] ?? 4);

        return $this->codeGeneratorService->preview($rule, $this->withScopeContext($attributes, $context));
    }

    public function executeForRule(CodeRule $codeRule, array $context = []): string
    {
        return $this->codeGeneratorService->preview($codeRule, $this->withScopeContext([
            'scope_type' => $codeRule->scope_type,
            'scope_id' => $codeRule->scope_id,
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withScopeContext(array $attributes, array $context): array
    {
        $scopeType = $attributes['scope_type'] ?? null;
        $scopeId = $attributes['scope_id'] ?? null;

        if ($scopeType === null || $scopeId === null || $scopeId === '') {
            return $context;
        }

        return match ($scopeType instanceof CodeRuleScopeType ? $scopeType->value : $scopeType) {
            CodeRuleScopeType::Organization->value => $context + ['organization_id' => $scopeId],
            CodeRuleScopeType::OrganizationType->value => $context + ['organization_type_id' => $scopeId],
            CodeRuleScopeType::OrganizationUnitType->value => $context + ['organization_unit_type_id' => $scopeId],
            CodeRuleScopeType::ServiceType->value => $context + ['service_type_id' => $scopeId],
            default => $context,
        };
    }
}
