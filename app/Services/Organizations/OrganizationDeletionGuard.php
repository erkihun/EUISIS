<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
     * Service-module tables whose rows reference an organization, registered by
     * the owning module (ServiceModuleServiceProvider), so the guard never
     * depends on a service module (backbone-refactor-migration.md, Stage 5).
     *
     * @var array<string, list<string>> table => organization id columns
     */
    private static array $moduleDependents = [];

    /** A service module's table that references organizations through these columns. */
    public static function registerDependent(string $table, string ...$columns): void
    {
        self::$moduleDependents[$table] = array_values(array_unique([...(self::$moduleDependents[$table] ?? []), ...$columns]));
    }

    /**
     * Every table and column that blocks deleting an organization: the
     * backbone's own and the ones service modules registered.
     *
     * @return array<string, list<string>>
     */
    public static function dependents(): array
    {
        $dependents = self::CORE_DEPENDENTS;
        foreach (self::$moduleDependents as $table => $columns) {
            $dependents[$table] = array_values(array_unique([...($dependents[$table] ?? []), ...$columns]));
        }

        return $dependents;
    }

    /**
     * The backbone's own references. Audit logs and organization name history
     * are intentionally excluded: both are immutable historical records and
     * safely retain the UUID.
     */
    private const CORE_DEPENDENTS = [
        'organization_change_requests' => ['organization_id'],
        'user_organization_scopes' => ['organization_id'],
        'institution_offices' => ['institution_id', 'geographic_organization_id', 'structural_organization_id'],
        'position_establishments' => ['organization_id'],
        'position_occupancies' => ['organization_id'],
        'organization_seals' => ['organization_id'],
    ];

    /**
     * Resolve blockers for an index page with a fixed number of aggregate
     * queries instead of running the full guard once per row.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return array<string, list<string>>
     */
    public function reasonsFor(Collection $organizations): array
    {
        $ids = $organizations->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $result = array_fill_keys($ids, []);
        $add = function (iterable $organizationIds, string $reason) use (&$result): void {
            foreach (collect($organizationIds)->filter()->unique() as $organizationId) {
                if (array_key_exists((string) $organizationId, $result)) {
                    $result[(string) $organizationId][] = $reason;
                }
            }
        };

        $publishedVersionIds = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->pluck('id');

        if ($publishedVersionIds->isNotEmpty()) {
            $publishedEdges = OrganizationEdge::query()
                ->whereIn('hierarchy_version_id', $publishedVersionIds)
                ->where(fn ($query) => $query->whereIn('parent_organization_id', $ids)->orWhereIn('child_organization_id', $ids))
                ->get(['parent_organization_id', 'child_organization_id']);
            $add($publishedEdges->pluck('parent_organization_id')->merge($publishedEdges->pluck('child_organization_id')), 'usedInPublishedHierarchy');
        }

        $add(
            OrganizationEdge::query()
                ->whereIn('parent_organization_id', $ids)
                ->whereHas('childOrganization', fn ($query) => $query->where('status', OrganizationStatus::Active->value))
                ->pluck('parent_organization_id'),
            'hasChildOrganizations',
        );
        // One EXISTS probe per listed organization: plucking every unit,
        // position and assignment row (history included) of a 25-organization
        // page read hundreds of thousands of rows at city scale.
        $add($this->organizationIdsHaving($ids, new OrganizationUnit), 'hasOrganizationUnits');
        $add($this->organizationIdsHaving($ids, new Position), 'hasPositions');
        $add($this->organizationIdsHaving($ids, new EmployeeAssignment), 'hasEmployeeAssignments');

        $add($this->otherReferencedOrganizationIds($ids), 'hasOtherReferences');

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, string>
     */
    private function organizationIdsHaving(array $ids, Model $related): Collection
    {
        $table = $related->getTable();
        // Same rows the model query saw: soft-deleted units/positions do not block.
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($related), true);

        return Organization::withTrashed()
            ->whereIn('id', $ids)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from($table)
                ->whereColumn("{$table}.organization_id", 'organizations.id')
                ->when($softDeletes, fn ($q) => $q->whereNull("{$table}.deleted_at")))
            ->pluck('id');
    }

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
     * Rule 6: referenced by any other table in dependents(): providers,
     * transfers, vacancies, cafeteria, transport, grievances, institution
     * offices and the rest. Rows are counted whether or not they are soft
     * deleted. Audit logs deliberately do not block a soft delete: they retain
     * the organization UUID and remain the historical record of the operation.
     */
    private function hasOtherReferences(Organization $organization): bool
    {
        return $this->otherReferencedOrganizationIds([$organization->id])->isNotEmpty();
    }

    /**
     * Organizations among these that some dependent table still references.
     *
     * @param  list<string>  $organizationIds
     */
    private function otherReferencedOrganizationIds(array $organizationIds): Collection
    {
        $references = self::dependents();

        $found = collect();

        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $found = $found->merge(
                        DB::table($table)->whereIn($column, $organizationIds)->pluck($column),
                    );
                }
            }
        }

        return $found->filter()->unique()->values();
    }
}
