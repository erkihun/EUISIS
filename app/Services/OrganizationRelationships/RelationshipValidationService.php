<?php

declare(strict_types=1);

namespace App\Services\OrganizationRelationships;

use App\Enums\OrganizationRelationshipType;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\InstitutionOffice;
use App\Models\InstitutionOfficeRelationship;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

readonly class RelationshipValidationService
{
    public function validateTargetExists(RelationshipTargetType $targetType, string $targetId): Model
    {
        $target = match ($targetType) {
            RelationshipTargetType::Organization => Organization::query()->find($targetId),
            RelationshipTargetType::InstitutionOffice => InstitutionOffice::query()->find($targetId),
            RelationshipTargetType::OrganizationUnit => OrganizationUnit::query()->find($targetId),
        };

        if (! $target instanceof Model) {
            throw ValidationException::withMessages([
                'target_id' => __('relationships.validation.target_not_found'),
            ]);
        }

        return $target;
    }

    public function validateEffectiveDates(?string $effectiveFrom, ?string $effectiveTo): void
    {
        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_to' => __('relationships.validation.invalid_effective_dates'),
            ]);
        }
    }

    public function validateOfficeRelationship(array $attributes, ?InstitutionOfficeRelationship $ignore = null): void
    {
        $targetType = RelationshipTargetType::from($attributes['target_type']);
        $relationshipType = OrganizationRelationshipType::from($attributes['relationship_type']);
        $status = RelationshipStatus::from($attributes['status'] ?? RelationshipStatus::Active->value);
        $this->validateTargetExists($targetType, $attributes['target_id']);
        $this->validateEffectiveDates($attributes['effective_from'] ?? null, $attributes['effective_to'] ?? null);

        if ($relationshipType === OrganizationRelationshipType::StructuralParent) {
            $this->validateOfficeStructuralTarget($targetType);
            $this->validateOnlyOnePrimaryOfficeStructuralParent($attributes, $status, $ignore);
            $this->validateNoOfficeStructuralCycle($attributes['source_office_id'], $targetType, $attributes['target_id']);
        }
    }

    public function validateUnitRelationship(array $attributes, ?OrganizationUnitRelationship $ignore = null): void
    {
        $targetType = RelationshipTargetType::from($attributes['target_type']);
        $relationshipType = OrganizationRelationshipType::from($attributes['relationship_type']);
        $status = RelationshipStatus::from($attributes['status'] ?? RelationshipStatus::Active->value);
        $this->validateTargetExists($targetType, $attributes['target_id']);
        $this->validateEffectiveDates($attributes['effective_from'] ?? null, $attributes['effective_to'] ?? null);
        $this->validateNoDuplicateActiveUnitRelationship($attributes, $status, $ignore);

        if ($relationshipType === OrganizationRelationshipType::StructuralParent) {
            $this->validateUnitStructuralTarget($targetType);
            $this->validateOnlyOnePrimaryUnitStructuralParent($attributes, $status, $ignore);
            $this->validateNoUnitStructuralCycle($attributes['source_unit_id'], $targetType, $attributes['target_id']);
        } elseif (! in_array($targetType, [RelationshipTargetType::Organization, RelationshipTargetType::OrganizationUnit], true)) {
            throw ValidationException::withMessages([
                'target_type' => __('relationships.validation.invalid_unit_relationship_target'),
            ]);
        }
    }

    private function validateOfficeStructuralTarget(RelationshipTargetType $targetType): void
    {
        if (! in_array($targetType, [RelationshipTargetType::Organization, RelationshipTargetType::InstitutionOffice], true)) {
            throw ValidationException::withMessages([
                'target_type' => __('relationships.validation.invalid_structural_target'),
            ]);
        }
    }

    private function validateUnitStructuralTarget(RelationshipTargetType $targetType): void
    {
        if (! in_array($targetType, [RelationshipTargetType::Organization, RelationshipTargetType::OrganizationUnit], true)) {
            throw ValidationException::withMessages([
                'target_type' => __('relationships.validation.invalid_structural_target'),
            ]);
        }
    }

    private function validateOnlyOnePrimaryOfficeStructuralParent(array $attributes, RelationshipStatus $status, ?InstitutionOfficeRelationship $ignore): void
    {
        if (($attributes['is_primary'] ?? false) !== true || $status !== RelationshipStatus::Active) {
            return;
        }

        $exists = InstitutionOfficeRelationship::query()
            ->where('source_office_id', $attributes['source_office_id'])
            ->where('relationship_type', OrganizationRelationshipType::StructuralParent->value)
            ->where('status', RelationshipStatus::Active->value)
            ->where('is_primary', true)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'is_primary' => __('relationships.validation.only_one_primary_structural_parent'),
            ]);
        }
    }

    private function validateOnlyOnePrimaryUnitStructuralParent(array $attributes, RelationshipStatus $status, ?OrganizationUnitRelationship $ignore): void
    {
        if (($attributes['is_primary'] ?? false) !== true || $status !== RelationshipStatus::Active) {
            return;
        }

        $exists = OrganizationUnitRelationship::query()
            ->where('source_unit_id', $attributes['source_unit_id'])
            ->where('relationship_type', OrganizationRelationshipType::StructuralParent->value)
            ->where('status', RelationshipStatus::Active->value)
            ->where('is_primary', true)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'is_primary' => __('relationships.validation.only_one_primary_structural_parent'),
            ]);
        }
    }

    private function validateNoDuplicateActiveUnitRelationship(array $attributes, RelationshipStatus $status, ?OrganizationUnitRelationship $ignore): void
    {
        if ($status !== RelationshipStatus::Active) {
            return;
        }

        $exists = OrganizationUnitRelationship::query()
            ->where('source_unit_id', $attributes['source_unit_id'])
            ->where('target_type', $attributes['target_type'])
            ->where('target_id', $attributes['target_id'])
            ->where('relationship_type', $attributes['relationship_type'])
            ->where('status', RelationshipStatus::Active->value)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'target_id' => __('relationships.validation.duplicate_active_relationship'),
            ]);
        }
    }

    private function validateNoOfficeStructuralCycle(string $sourceOfficeId, RelationshipTargetType $targetType, string $targetId): void
    {
        if ($targetType !== RelationshipTargetType::InstitutionOffice) {
            return;
        }

        $current = InstitutionOffice::query()->find($targetId);
        while ($current instanceof InstitutionOffice) {
            if ($current->id === $sourceOfficeId) {
                throw ValidationException::withMessages([
                    'target_id' => __('relationships.validation.structural_cycle'),
                ]);
            }

            $current = $current->parentOffice;
        }
    }

    private function validateNoUnitStructuralCycle(string $sourceUnitId, RelationshipTargetType $targetType, string $targetId): void
    {
        if ($targetType !== RelationshipTargetType::OrganizationUnit) {
            return;
        }

        $current = OrganizationUnit::query()->find($targetId);
        while ($current instanceof OrganizationUnit) {
            if ($current->id === $sourceUnitId) {
                throw ValidationException::withMessages([
                    'target_id' => __('relationships.validation.structural_cycle'),
                ]);
            }

            $current = $current->parent;
        }
    }
}
