<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\CodeRules\PreviewCodeRuleAction;
use App\Enums\CodeRuleScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CodeRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type?->value ?? $this->entity_type,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'scope_label' => $this->resolveScopeLabel(),
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'format' => $this->format,
            'separator' => $this->separator,
            'sequence_length' => $this->sequence_length,
            'next_number' => $this->next_number,
            'sequence_scope_strategy' => $this->sequence_scope_strategy?->value ?? ($this->sequence_scope_strategy ?? 'auto'),
            'sequence_scope_tokens' => $this->sequence_scope_tokens ?? [],
            'reset_frequency' => $this->reset_frequency?->value ?? $this->reset_frequency,
            'last_reset_at' => $this->last_reset_at?->toIso8601String(),
            'year_format' => $this->year_format,
            'is_active' => $this->is_active,
            'allow_manual_override' => $this->allow_manual_override,
            'require_approval_for_override' => $this->require_approval_for_override,
            'description_en' => $this->description_en,
            'description_am' => $this->description_am,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => $this->creator?->only(['id', 'name']),
            'updated_by' => $this->updater?->only(['id', 'name']),
            'preview' => app(PreviewCodeRuleAction::class)->executeForRule($this->resource),
            'logs_count' => $this->whenCounted('generationLogs'),
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }

    private function resolveScopeLabel(): ?string
    {
        if ($this->scope_type === null) {
            return null;
        }

        return match ($this->scope_type) {
            CodeRuleScopeType::Organization->value => $this->scope_id !== null
                ? Organization::query()->whereKey($this->scope_id)->value('name_en')
                : __('code-rules.scope_type_organization'),
            CodeRuleScopeType::OrganizationType->value => $this->scope_id !== null
                ? OrganizationType::query()->whereKey($this->scope_id)->value('name_en')
                : __('code-rules.scope_type_organization_type'),
            CodeRuleScopeType::OrganizationUnitType->value => $this->scope_id !== null
                ? OrganizationUnitType::query()->whereKey($this->scope_id)->value('name_en')
                : __('code-rules.scope_type_organization_unit_type'),
            CodeRuleScopeType::ServiceType->value => $this->scope_id !== null
                ? ServiceType::query()->whereKey($this->scope_id)->value('name_en')
                : __('code-rules.scope_type_service_type'),
            default => null,
        };
    }
}
