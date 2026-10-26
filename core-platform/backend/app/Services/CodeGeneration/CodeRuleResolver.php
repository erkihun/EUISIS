<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use App\Models\OrganizationUnit;

class CodeRuleResolver
{
    public function resolve(CodeRuleEntityType|string $entityType, array $context = []): ?CodeRule
    {
        $entityValue = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;

        foreach ($this->buildScopeCandidates($context) as $candidate) {
            $rule = CodeRule::query()
                ->where('entity_type', $entityValue)
                ->where('scope_type', $candidate['scope_type'])
                ->where('scope_id', $candidate['scope_id'])
                ->where('is_active', true)
                ->first();

            if ($rule !== null) {
                return $rule;
            }

            if ($candidate['scope_type'] !== null && $candidate['scope_id'] !== null) {
                $typeOnlyRule = CodeRule::query()
                    ->where('entity_type', $entityValue)
                    ->where('scope_type', $candidate['scope_type'])
                    ->whereNull('scope_id')
                    ->where('is_active', true)
                    ->first();

                if ($typeOnlyRule !== null) {
                    return $typeOnlyRule;
                }
            }
        }

        return CodeRule::query()
            ->where('entity_type', $entityValue)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<int, array{scope_type: string|null, scope_id: string|null}>
     */
    public function buildScopeCandidates(array $context): array
    {
        $candidates = [];
        $organizationUnitTypeId = $context['organization_unit_type_id'] ?? null;

        if (($organizationUnitTypeId === null || $organizationUnitTypeId === '') && ! empty($context['organization_unit_id'])) {
            $organizationUnitTypeId = OrganizationUnit::query()
                ->whereKey($context['organization_unit_id'])
                ->value('organization_unit_type_id');
        }

        if (($context['scope_candidates'] ?? null) !== null) {
            foreach ((array) $context['scope_candidates'] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $candidates[] = [
                    'scope_type' => $candidate['scope_type'] ?? null,
                    'scope_id' => $candidate['scope_id'] ?? null,
                ];
            }
        }

        foreach ([
            'organization' => $context['organization_id'] ?? null,
            'organization_type' => $context['organization_type_id'] ?? null,
            'organization_unit_type' => $organizationUnitTypeId,
            'service_type' => $context['service_type_id'] ?? null,
        ] as $scopeType => $scopeId) {
            if ($scopeId !== null && $scopeId !== '') {
                $candidates[] = [
                    'scope_type' => $scopeType,
                    'scope_id' => (string) $scopeId,
                ];
            }
        }

        if (($context['scope_type'] ?? null) !== null) {
            $candidates[] = [
                'scope_type' => (string) $context['scope_type'],
                'scope_id' => ($context['scope_id'] ?? null) !== null && $context['scope_id'] !== ''
                    ? (string) $context['scope_id']
                    : null,
            ];
        }

        $unique = [];

        foreach ($candidates as $candidate) {
            $key = implode('|', [
                $candidate['scope_type'] ?? 'global',
                $candidate['scope_id'] ?? 'global',
            ]);

            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }
}
