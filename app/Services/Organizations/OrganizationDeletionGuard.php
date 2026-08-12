<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\HierarchyVersion;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\Provider;
use App\Models\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether an Organization can be physically (soft-)deleted.
 *
 * A government organization record is never truly disposable while it has
 * history — the safe alternative is always Deactivate/Archive. This guard
 * enumerates every reason a delete must be blocked so both the policy (server
 * authorization) and the UI (disabled button + reason list) can share one
 * source of truth.
 */
class OrganizationDeletionGuard
{
    /**
     * Ordered list of blocking reason keys for the given organization.
     * Empty array means the organization is safe to delete.
     *
     * @return list<string>
     */
    public function reasons(Organization $organization): array
    {
        $reasons = [];

        if ($this->usedInPublishedHierarchy($organization)) {
            $reasons[] = 'usedInPublishedHierarchy';
        }

        if ($this->hasActiveChildOrganizations($organization)) {
            $reasons[] = 'hasChildOrganizations';
        }

        if ($this->hasOrganizationUnits($organization)) {
            $reasons[] = 'hasOrganizationUnits';
        }

        if ($this->hasPositions($organization)) {
            $reasons[] = 'hasPositions';
        }

        if ($this->hasEmployeeAssignments($organization)) {
            $reasons[] = 'hasEmployeeAssignments';
        }

        if ($this->hasOtherReferences($organization)) {
            $reasons[] = 'hasOtherReferences';
        }

        // Developer observability: only in local/testing, and only when a
        // delete is actually blocked. Never runs (no extra queries) in
        // production, and debug counts are never exposed to end users.
        if ($reasons !== [] && App::environment(['local', 'testing'])) {
            Log::debug('OrganizationDeletionGuard blocked delete', [
                'organization_id' => $organization->id,
                'organization_code' => $organization->code,
                'reasons' => $reasons,
                'counts' => $this->debugCounts($organization),
            ]);
        }

        return $reasons;
    }

    public function canBeDeletedSafely(Organization $organization): bool
    {
        return $this->reasons($organization) === [];
    }

    /**
     * Debug-safe dependency counts for the given organization. Used for
     * developer logging and diagnostics — never surfaced to normal users.
     * Crucially distinguishes published vs draft vs archived hierarchy edges,
     * so a draft/archived-only organization is provably not "used in a
     * published hierarchy".
     *
     * @return array<string, int>
     */
    public function debugCounts(Organization $organization): array
    {
        $id = $organization->id;
        $edgeForOrg = fn ($query) => $query->where('parent_organization_id', $id)->orWhere('child_organization_id', $id);

        $edgesForStatus = fn (string $status): int => OrganizationEdge::query()
            ->whereIn('hierarchy_version_id', HierarchyVersion::query()->where('status', $status)->pluck('id'))
            ->where($edgeForOrg)
            ->count();

        return [
            'published_hierarchy_edges' => $edgesForStatus(HierarchyVersionStatus::Published->value),
            'draft_hierarchy_edges' => $edgesForStatus(HierarchyVersionStatus::Draft->value),
            'archived_hierarchy_edges' => $edgesForStatus(HierarchyVersionStatus::Archived->value),
            'active_child_edges' => OrganizationEdge::query()->where('parent_organization_id', $id)->count(),
            'organization_units' => $organization->organizationUnits()->count(),
            'positions' => $organization->positions()->count(),
            'employee_assignments' => EmployeeAssignment::query()->where('organization_id', $id)->count(),
        ];
    }

    /** Rule 1: the organization appears as parent or child in a published hierarchy version. */
    private function usedInPublishedHierarchy(Organization $organization): bool
    {
        $publishedVersionIds = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->pluck('id');

        if ($publishedVersionIds->isEmpty()) {
            return false;
        }

        return OrganizationEdge::query()
            ->whereIn('hierarchy_version_id', $publishedVersionIds)
            ->where(function ($query) use ($organization): void {
                $query->where('parent_organization_id', $organization->id)
                    ->orWhere('child_organization_id', $organization->id);
            })
            ->exists();
    }

    /** Rule 2: has at least one active child organization (any hierarchy version). */
    private function hasActiveChildOrganizations(Organization $organization): bool
    {
        return OrganizationEdge::query()
            ->where('parent_organization_id', $organization->id)
            ->whereHas('childOrganization', fn ($query) => $query->where('status', OrganizationStatus::Active->value))
            ->exists();
    }

    /** Rule 3: has organization units (soft-deleted units do not count). */
    private function hasOrganizationUnits(Organization $organization): bool
    {
        return $organization->organizationUnits()->exists();
    }

    /** Rule 4: has positions (soft-deleted positions do not count). */
    private function hasPositions(Organization $organization): bool
    {
        return $organization->positions()->exists();
    }

    /** Rule 5: has employee assignments recorded against it. */
    private function hasEmployeeAssignments(Organization $organization): bool
    {
        return EmployeeAssignment::query()
            ->where('organization_id', $organization->id)
            ->exists();
    }

    /**
     * Rule 6: referenced by providers, transfers, institution offices, or
     * audit-sensitive history. Reports are computed on demand and hold no
     * persisted reference, so they are not checked here.
     */
    private function hasOtherReferences(Organization $organization): bool
    {
        $id = $organization->id;

        if (ServiceProvider::query()->where('organization_id', $id)->exists()) {
            return true;
        }

        if (Provider::query()->where('assigned_organization_id', $id)->exists()) {
            return true;
        }

        if (EmployeeTransfer::query()
            ->where('from_organization_id', $id)
            ->orWhere('to_organization_id', $id)
            ->exists()) {
            return true;
        }

        if (InstitutionOffice::query()
            ->where('institution_id', $id)
            ->orWhere('geographic_organization_id', $id)
            ->orWhere('structural_organization_id', $id)
            ->exists()) {
            return true;
        }

        return AuditLog::query()->where('organization_id', $id)->exists();
    }
}
