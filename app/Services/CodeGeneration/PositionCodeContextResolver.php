<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the owner/host organization pair used to compose job position codes.
 *
 * Business rule: an organization unit (office/department/service office) may
 * belong functionally to one organization (the OWNER of the mandate) while
 * operating inside another (the HOST). In that case the position code is
 * `OWNER_CODE/HOST_CODE/SEQUENCE`; otherwise it is `OWNER_CODE/SEQUENCE`.
 *
 * The project already models this: a hosted office is an OrganizationUnit
 * created under the HOST organization (`organization_units.organization_id`),
 * with an active `organization_unit_relationships` row of type
 * `functional_reporting` targeting the OWNER organization (this is how the
 * Institution Offices flow records "belongs to institution"). A legacy
 * fallback is `organization_units.institution_office_id →
 * institution_offices.institution_id`. No new fields are introduced here.
 */
class PositionCodeContextResolver
{
    /** Safety bound when walking up the parent-unit chain. */
    private const MAX_ANCESTOR_DEPTH = 25;

    /**
     * @return array{owner_organization_id: string|null, host_organization_id: string|null}
     */
    public function resolve(?string $organizationId, ?string $organizationUnitId): array
    {
        if (empty($organizationUnitId)) {
            return [
                'owner_organization_id' => $organizationId ?: null,
                'host_organization_id' => null,
            ];
        }

        $unit = OrganizationUnit::query()
            ->whereKey($organizationUnitId)
            ->first(['id', 'organization_id', 'institution_office_id', 'parent_unit_id']);

        if ($unit === null) {
            return [
                'owner_organization_id' => $organizationId ?: null,
                'host_organization_id' => null,
            ];
        }

        // The unit lives inside its organization; that organization is the
        // HOST candidate. Prefer the unit's own organization over the passed
        // organization_id so the pair stays consistent even if they diverge.
        $unitOrganizationId = $unit->organization_id ?? $organizationId;

        $ownerId = $this->resolveOwnerOrganizationId($unit);

        if ($ownerId !== null && $ownerId !== $unitOrganizationId) {
            return [
                'owner_organization_id' => $ownerId,
                'host_organization_id' => $unitOrganizationId,
            ];
        }

        return [
            'owner_organization_id' => $unitOrganizationId ?: null,
            'host_organization_id' => null,
        ];
    }

    /**
     * Validate that code generation is allowed for the resolved pair.
     * Inactive (or missing) owner/host organizations cannot receive new codes.
     *
     * @return array{owner_organization_id: string, host_organization_id: string|null}
     *
     * @throws ValidationException
     */
    public function validateForGeneration(?string $organizationId, ?string $organizationUnitId, string $field = 'job_position_code'): array
    {
        $resolved = $this->resolve($organizationId, $organizationUnitId);

        $ownerId = $resolved['owner_organization_id'];

        if ($ownerId === null) {
            throw ValidationException::withMessages([
                $field => __('code-rules.owner_organization_required'),
            ]);
        }

        if (! $this->isActiveOrganization($ownerId)) {
            throw ValidationException::withMessages([
                $field => __('code-rules.owner_organization_inactive'),
            ]);
        }

        $hostId = $resolved['host_organization_id'];

        if ($hostId !== null && ! $this->isActiveOrganization($hostId)) {
            throw ValidationException::withMessages([
                $field => __('code-rules.host_organization_inactive'),
            ]);
        }

        return ['owner_organization_id' => $ownerId, 'host_organization_id' => $hostId];
    }

    /**
     * The organization that owns the unit's function/mandate, or null when the
     * unit is a plain internal unit of its own organization.
     *
     * Child units inherit the owner from their ancestors: the functional
     * relationship (or institution office link) is recorded on the top office
     * unit only, so this walks up the parent chain until an owner is found.
     */
    private function resolveOwnerOrganizationId(OrganizationUnit $unit): ?string
    {
        $current = $unit;
        $visited = [];
        $depth = 0;

        while ($current !== null && $depth < self::MAX_ANCESTOR_DEPTH && ! in_array($current->id, $visited, true)) {
            $visited[] = $current->id;
            $depth++;

            $ownerId = $this->ownOwnerOrganizationId($current);

            if ($ownerId !== null) {
                return $ownerId;
            }

            $current = $current->parent_unit_id !== null
                ? OrganizationUnit::query()
                    ->whereKey($current->parent_unit_id)
                    ->first(['id', 'organization_id', 'institution_office_id', 'parent_unit_id'])
                : null;
        }

        return null;
    }

    /**
     * The owner recorded directly on this unit (no ancestor traversal).
     */
    private function ownOwnerOrganizationId(OrganizationUnit $unit): ?string
    {
        $relationshipOwnerId = OrganizationUnitRelationship::query()
            ->where('source_unit_id', $unit->id)
            ->where('target_type', RelationshipTargetType::Organization->value)
            ->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value)
            ->where('status', RelationshipStatus::Active->value)
            ->orderByDesc('is_primary')
            ->value('target_id');

        if ($relationshipOwnerId !== null) {
            return (string) $relationshipOwnerId;
        }

        if ($unit->institution_office_id !== null) {
            $institutionId = $unit->institutionOffice()->value('institution_id');

            if ($institutionId !== null) {
                return (string) $institutionId;
            }
        }

        return null;
    }

    private function isActiveOrganization(string $organizationId): bool
    {
        $status = Organization::query()->whereKey($organizationId)->value('status');

        if ($status === null) {
            return false;
        }

        $value = $status instanceof OrganizationStatus ? $status->value : (string) $status;

        return $value === OrganizationStatus::Active->value;
    }
}
