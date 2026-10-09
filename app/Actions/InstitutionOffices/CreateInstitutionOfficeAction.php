<?php

declare(strict_types=1);

namespace App\Actions\InstitutionOffices;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitType;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use App\Models\User;
use App\Services\OrganizationRelationships\OrganizationUnitRelationshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

readonly class CreateInstitutionOfficeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
        private OrganizationUnitRelationshipService $relationshipService,
    ) {}

    public function execute(array $attributes, User $actor, ?Request $request = null): OrganizationUnit
    {
        $functionalReportingOrganizationId = $attributes['functional_reporting_organization_id'] ?? null;
        $relationshipType = $attributes['relationship_type'] ?? OrganizationRelationshipType::FunctionalReporting->value;

        unset(
            $attributes['functional_reporting_organization_id'],
            $attributes['relationship_type'],
        );

        $attributes['unit_type'] = $this->resolveUnitType($attributes['organization_unit_type_id'] ?? null);
        $attributes['metadata'] = array_merge($attributes['metadata'] ?? [], [
            'created_from' => 'institution_offices_create_page',
            'institution_office_legacy_source' => false,
        ]);

        $attributes['code'] = $this->generateCodeAction->execute(
            CodeRuleEntityType::OrganizationUnit,
            [
                'organization_id' => $attributes['organization_id'] ?? null,
                'organization_unit_type_id' => $attributes['organization_unit_type_id'] ?? null,
            ],
            $actor,
            $attributes['code'] ?? null,
            'code',
        );

        $attributes['created_by'] = $actor->getKey();
        $attributes['updated_by'] = $actor->getKey();

        return DB::transaction(function () use ($attributes, $actor, $request, $functionalReportingOrganizationId, $relationshipType): OrganizationUnit {
            $unit = OrganizationUnit::query()->create($attributes);

            if ($functionalReportingOrganizationId !== null) {
                $this->relationshipService->create([
                    'source_unit_id' => $unit->id,
                    'target_type' => RelationshipTargetType::Organization->value,
                    'target_id' => $functionalReportingOrganizationId,
                    'relationship_type' => $relationshipType,
                    'is_primary' => false,
                    'status' => RelationshipStatus::Active->value,
                ], $actor, $request);
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUnitCreated,
                $actor,
                $unit,
                $unit->organization_id,
                newValues: array_merge($unit->toArray(), [
                    'created_from' => 'institution_offices_create_page',
                    'functional_reporting_organization_id' => $functionalReportingOrganizationId,
                    'relationship_type' => $relationshipType,
                ]),
                request: $request,
            );

            return $unit;
        });
    }

    private function resolveUnitType(?string $organizationUnitTypeId): string
    {
        if ($organizationUnitTypeId === null || $organizationUnitTypeId === '') {
            return OrganizationUnitType::Unit->value;
        }

        $type = OrganizationUnitTypeModel::query()->find($organizationUnitTypeId, ['code']);

        return $type?->code ?? OrganizationUnitType::Unit->value;
    }
}
