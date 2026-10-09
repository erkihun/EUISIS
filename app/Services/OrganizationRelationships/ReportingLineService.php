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
use Illuminate\Support\Collection;

readonly class ReportingLineService
{
    public function getStructuralParent(InstitutionOffice $office): ?InstitutionOfficeRelationship
    {
        return $office->relationships()
            ->active()
            ->structuralParent()
            ->where('is_primary', true)
            ->first();
    }

    public function getFunctionalReportingTargets(InstitutionOffice $office): Collection
    {
        return $this->officeRelationships($office, OrganizationRelationshipType::FunctionalReporting);
    }

    public function getTechnicalSupervisionTargets(InstitutionOffice $office): Collection
    {
        return $this->officeRelationships($office, OrganizationRelationshipType::TechnicalSupervision);
    }

    public function getAllActiveReportingLines(InstitutionOffice|OrganizationUnit $source): Collection
    {
        return $source->relationships()->active()->secondary()->orderBy('relationship_type')->get();
    }

    public function getOfficesReportingToOrganization(Organization $organization, ?string $relationshipType = null): Collection
    {
        return InstitutionOfficeRelationship::query()
            ->active()
            ->where('target_type', RelationshipTargetType::Organization->value)
            ->where('target_id', $organization->id)
            ->when($relationshipType, fn ($query) => $query->where('relationship_type', $relationshipType))
            ->with('sourceOffice')
            ->get();
    }

    public function getUnitsReportingToOrganization(Organization $organization, ?string $relationshipType = null): Collection
    {
        return OrganizationUnitRelationship::query()
            ->active()
            ->where('target_type', RelationshipTargetType::Organization->value)
            ->where('target_id', $organization->id)
            ->when($relationshipType, fn ($query) => $query->where('relationship_type', $relationshipType))
            ->with('sourceUnit')
            ->get();
    }

    private function officeRelationships(InstitutionOffice $office, OrganizationRelationshipType $type): Collection
    {
        return $office->relationships()
            ->where('status', RelationshipStatus::Active->value)
            ->where('relationship_type', $type->value)
            ->get();
    }
}
