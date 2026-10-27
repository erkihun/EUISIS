<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;

/**
 * What an organization is still used by, as precise reasons:
 *  - it may leave service (inactive, merged, dissolved, archived) only when no
 *    child organization, unit, position or placement is still active under it;
 *  - it may be deleted only when nothing ever referred to it: no hierarchy
 *    version (published, archived or in preparation), unit, position,
 *    establishment, placement past or present, or scoped account. A deleted
 *    organization is soft-deleted, so its audit trail still resolves.
 */
final class OrganizationUsage
{
    /** Statuses of an organization that is out of service. */
    public const ENDED = ['inactive', 'merged', 'dissolved', 'archived'];

    /** @return array<string, string> reason code => message */
    public function deactivationBlockers(Organization $organization): array
    {
        $id = (string) $organization->id;
        $version = HierarchyVersion::current();
        $activeChildren = $version === null ? 0 : OrganizationEdge::query()
            ->where('hierarchy_version_id', $version->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->where('parent_organization_id', $id)
            ->whereIn('child_organization_id', Organization::query()->whereNotIn('status', self::ENDED)->select('id'))
            ->count();

        return array_filter([
            'child_organizations' => $activeChildren > 0 ? "{$activeChildren} organization(s) under it in the published hierarchy are still in service; move or end them first." : null,
            'current_placements' => ($n = EmployeeAssignment::query()->where('organization_id', $id)->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE)->count()) > 0
                ? "{$n} employee(s) are placed here; transfer them first." : null,
            'active_positions' => ($n = Position::query()->where('organization_id', $id)->where('is_active', true)->count()) > 0
                ? "{$n} position(s) are active; retire or move them first." : null,
            'active_units' => ($n = OrganizationUnit::query()->where('organization_id', $id)->whereNotIn('status', ['inactive', 'archived'])->count()) > 0
                ? "{$n} unit(s) are still active; switch them off first." : null,
        ]);
    }

    /** @return array<string, string> reason code => message */
    public function deletionBlockers(Organization $organization): array
    {
        $id = (string) $organization->id;
        $edges = OrganizationEdge::query()->where(fn ($q) => $q->where('parent_organization_id', $id)->orWhere('child_organization_id', $id));

        return array_filter([
            'hierarchy_history' => (clone $edges)->whereIn('hierarchy_version_id', HierarchyVersion::query()->whereIn('status', [HierarchyVersion::PUBLISHED, 'archived'])->select('id'))->exists()
                ? 'It is part of a published or historical hierarchy; archive it instead.' : null,
            'hierarchy_drafts' => (clone $edges)->whereIn('hierarchy_version_id', HierarchyVersion::query()->whereNotIn('status', [HierarchyVersion::PUBLISHED, 'archived'])->select('id'))->exists()
                ? 'A hierarchy version in preparation places it; remove it there first.' : null,
            'units' => OrganizationUnit::withTrashed()->where('organization_id', $id)->exists() ? 'It has units; archive it instead.' : null,
            'positions' => Position::withTrashed()->where('organization_id', $id)->exists() ? 'It has positions; archive it instead.' : null,
            'establishments' => PositionEstablishment::withTrashed()->where('organization_id', $id)->exists() ? 'It has approved establishments; archive it instead.' : null,
            'assignments' => EmployeeAssignment::query()->where('organization_id', $id)->exists() ? 'Employees are or were placed here; archive it instead.' : null,
            'scoped_accounts' => User::query()->where('scope_organization_id', $id)->exists() ? 'Core accounts are scoped to it; change their scope first.' : null,
        ]);
    }
}
