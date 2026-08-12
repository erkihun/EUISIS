<?php

declare(strict_types=1);

namespace App\Services\Hierarchy;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HierarchyTreeService
{
    public function __construct(
        private readonly OrganizationScopeService $scopeService,
    ) {}

    /**
     * Return the locale-aware display name, falling back to the English name.
     */
    private function localizedName(?string $nameEn, ?string $nameAm): string
    {
        $locale = app()->getLocale();
        if ($locale === 'am' && filled($nameAm)) {
            return $nameAm;
        }

        return $nameEn ?? $nameAm ?? '';
    }

    /**
     * Build the full hierarchy tree for a given version, optionally scoped to a user.
     *
     * The returned shape for each organization node is:
     * [
     *   'id'             => string (organization id),
     *   'type'           => 'organization',
     *   'label'          => string,
     *   'code'           => string|null,
     *   'status'         => string,
     *   'node_type_label'=> string,
     *   'children'       => array  (child org nodes + unit nodes interleaved),
     *   'meta'           => [...],
     *   // legacy keys kept for backwards compat with existing HierarchyTreeNodeResource:
     *   'organization_id', 'edge_id', 'parent_organization_id', 'name_en', 'name_am',
     *   'organization_type', 'logo_url', 'depth', 'child_count', 'relationship_type',
     *   'effective_from', 'effective_to', 'can',
     * ]
     *
     * @param  array{include_units?: bool, include_inactive?: bool, search?: string|null}  $filters
     * @return array<int, mixed>
     */
    public function buildFullTree(
        HierarchyVersion $version,
        ?User $user = null,
        array $filters = [],
    ): array {
        $includeUnits = (bool) ($filters['include_units'] ?? true);
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        // ── 1. Load all edges for this version ──────────────────────────────
        $edges = $version->edges()->get([
            'id',
            'parent_organization_id',
            'child_organization_id',
            'relationship_type',
            'effective_from',
            'effective_to',
        ]);

        if ($edges->isEmpty()) {
            return [];
        }

        $allOrgIds = $edges->pluck('parent_organization_id')
            ->merge($edges->pluck('child_organization_id'))
            ->unique()
            ->values();

        // Apply user scope
        if ($user !== null && ! $user->hasRole('Super Admin') && ! $user->hasRole('City Admin')) {
            $allowed = $this->scopeService->accessibleOrganizationIds($user);
            $allOrgIds = $allOrgIds->intersect($allowed)->values();

            if ($allOrgIds->isEmpty()) {
                return [];
            }
        }

        // ── 2. Load all organizations in one query ──────────────────────────
        $organizations = Organization::query()
            ->whereIn('id', $allOrgIds)
            ->with('type:id,code,name_en,name_am')
            ->get()
            ->keyBy('id');

        // ── 3. Load all org units in one query per all relevant orgs ────────
        $unitsByOrg = collect(); // keyed by organization_id
        $allUnits = collect();   // flat collection of all units
        $unitTypeMap = collect(); // id => OrganizationUnitType

        if ($includeUnits) {
            $unitQuery = OrganizationUnit::query()
                ->whereIn('organization_id', $allOrgIds->all())
                ->orderBy('sort_order')
                ->orderBy('name_en');

            if (! $includeInactive) {
                $unitQuery->active();
            }

            $allUnits = $unitQuery->get([
                'id',
                'organization_id',
                'parent_unit_id',
                'organization_unit_type_id',
                'code',
                'name_en',
                'name_am',
                'status',
                'sort_order',
            ]);

            $typeIds = $allUnits->pluck('organization_unit_type_id')->filter()->unique()->values()->all();

            if (! empty($typeIds)) {
                $unitTypeMap = OrganizationUnitType::query()
                    ->whereIn('id', $typeIds)
                    ->get(['id', 'name_en', 'name_am'])
                    ->keyBy('id');
            }

            $unitsByOrg = $allUnits->groupBy('organization_id');
        }

        // ── 4. Load counts per org in bulk ──────────────────────────────────
        // unit_count: number of top-level (root) units per org
        $unitCountByOrg = $includeUnits
            ? $allUnits->where('parent_unit_id', null)->groupBy('organization_id')
                ->map(fn (Collection $g) => $g->count())
            : collect();

        // position_count per org (positions belong to an org directly)
        $positionCountByOrg = DB::table('positions')
            ->whereIn('organization_id', $allOrgIds->all())
            ->whereNull('deleted_at')
            ->groupBy('organization_id')
            ->pluck(DB::raw('COUNT(*) as cnt'), 'organization_id')
            ->map(fn ($v) => (int) $v);

        // employee_count per org (active assignments)
        $employeeCountByOrg = DB::table('employee_assignments')
            ->whereIn('organization_id', $allOrgIds->all())
            ->where('is_current', true)
            ->groupBy('organization_id')
            ->pluck(DB::raw('COUNT(*) as cnt'), 'organization_id')
            ->map(fn ($v) => (int) $v);

        // ── 5. Load counts per unit in bulk (for unit nodes) ────────────────
        $positionCountByUnit = collect();
        $employeeCountByUnit = collect();

        if ($includeUnits && $allUnits->isNotEmpty()) {
            $allUnitIds = $allUnits->pluck('id')->all();

            $positionCountByUnit = DB::table('positions')
                ->whereIn('organization_unit_id', $allUnitIds)
                ->whereNull('deleted_at')
                ->groupBy('organization_unit_id')
                ->pluck(DB::raw('COUNT(*) as cnt'), 'organization_unit_id')
                ->map(fn ($v) => (int) $v);

            $employeeCountByUnit = DB::table('employee_assignments')
                ->whereIn('organization_unit_id', $allUnitIds)
                ->where('is_current', true)
                ->groupBy('organization_unit_id')
                ->pluck(DB::raw('COUNT(*) as cnt'), 'organization_unit_id')
                ->map(fn ($v) => (int) $v);
        }

        // ── 6. Determine root organizations ──────────────────────────────────
        $childIds = $edges->pluck('child_organization_id')->unique();
        $rootIds = $allOrgIds->diff($childIds)->values();
        $childrenByParent = $edges->groupBy('parent_organization_id');

        // ── 7. Build unit sub-tree helper ────────────────────────────────────
        $buildUnitNode = function (OrganizationUnit $unit, int $depth) use (
            &$buildUnitNode,
            $allUnits,
            $unitTypeMap,
            $positionCountByUnit,
            $employeeCountByUnit,
        ): array {
            $childUnits = $allUnits->filter(fn (OrganizationUnit $u) => $u->parent_unit_id === $unit->id);

            $unitType = $unit->organization_unit_type_id ? $unitTypeMap->get($unit->organization_unit_type_id) : null;

            $children = $childUnits
                ->map(fn (OrganizationUnit $child) => $buildUnitNode($child, $depth + 1))
                ->values()
                ->all();

            $unitStatusValue = $unit->status instanceof \BackedEnum ? $unit->status->value : (string) $unit->status;

            return [
                // New unified shape
                'id' => $unit->id,
                'type' => 'organization_unit',
                'label' => $this->localizedName($unit->name_en, $unit->name_am),
                'code' => $unit->code,
                'status' => $unitStatusValue,
                'status_label' => __('hierarchy-versions.'.$unitStatusValue),
                'node_type_label' => $this->localizedName($unitType?->name_en, $unitType?->name_am) ?: null,
                'node_type_label_am' => $unitType?->name_am ?? null,
                'children' => $children,
                'meta' => [
                    'organization_unit_type' => $unitType?->name_en,
                    'organization_unit_type_am' => $unitType?->name_am,
                    'position_count' => $positionCountByUnit->get($unit->id, 0),
                    'employee_count' => $employeeCountByUnit->get($unit->id, 0),
                    'functional_reports_to' => [],
                ],
                // Legacy / backwards-compat keys used by HierarchyTreeNodeResource
                'organization_id' => $unit->id,
                'edge_id' => null,
                'parent_organization_id' => null,
                'name_en' => $unit->name_en,
                'name_am' => $unit->name_am,
                'organization_type' => $unitType ? [
                    'code' => null,
                    'name_en' => $unitType->name_en,
                    'name_am' => $unitType->name_am,
                ] : null,
                'logo_url' => null,
                'depth' => $depth,
                'child_count' => count($children),
                'relationship_type' => null,
                'effective_from' => null,
                'effective_to' => null,
                'can' => [
                    'edit' => false,
                    'remove' => false,
                    'addChild' => false,
                ],
            ];
        };

        // ── 8. Build org node ─────────────────────────────────────────────────
        $buildOrgNode = function (string $orgId, int $depth, ?string $edgeId, ?string $parentOrgId, ?string $relationshipType, ?string $edgeFrom, ?string $edgeTo) use (
            &$buildOrgNode,
            $organizations,
            $childrenByParent,
            $unitsByOrg,
            $positionCountByOrg,
            $employeeCountByOrg,
            $unitCountByOrg,
            $includeUnits,
            $buildUnitNode,
            $version,
            $user,
        ): array {
            $org = $organizations->get($orgId);

            if ($org === null) {
                return [];
            }

            // Org child organizations
            $childEdges = $childrenByParent->get($orgId, collect());
            $childOrgNodes = $childEdges
                ->map(fn ($edge) => $buildOrgNode(
                    $edge->child_organization_id,
                    $depth + 1,
                    $edge->id,
                    $orgId,
                    $edge->relationship_type instanceof \BackedEnum ? $edge->relationship_type->value : (string) $edge->relationship_type,
                    $edge->effective_from?->toDateString(),
                    $edge->effective_to?->toDateString(),
                ))
                ->filter(fn (array $n) => $n !== [])
                ->values()
                ->all();

            // Org unit children (root units only, depth starts at depth+1)
            $unitNodes = [];
            if ($includeUnits) {
                $rootUnitsForOrg = ($unitsByOrg->get($orgId, collect()))
                    ->filter(fn (OrganizationUnit $u) => $u->parent_unit_id === null)
                    ->values();

                foreach ($rootUnitsForOrg as $unit) {
                    $unitNodes[] = $buildUnitNode($unit, $depth + 1);
                }
            }

            // Org units come after child organizations in the children array
            $allChildren = array_merge($childOrgNodes, $unitNodes);

            $orgType = $org->type;
            $orgStatusValue = $org->status instanceof \BackedEnum ? $org->status->value : (string) $org->status;

            return [
                // New unified shape
                'id' => $org->id,
                'type' => 'organization',
                'label' => $this->localizedName($org->name_en, $org->name_am),
                'code' => $org->code,
                'status' => $orgStatusValue,
                'status_label' => __('hierarchy-versions.'.$orgStatusValue),
                'node_type_label' => $this->localizedName($orgType?->name_en, $orgType?->name_am) ?: null,
                'node_type_label_am' => $orgType?->name_am ?? null,
                'children' => $allChildren,
                'meta' => [
                    'organization_type' => $orgType?->name_en,
                    'organization_type_am' => $orgType?->name_am,
                    'organization_unit_count' => $unitCountByOrg->get($orgId, 0),
                    'position_count' => $positionCountByOrg->get($orgId, 0),
                    'employee_count' => $employeeCountByOrg->get($orgId, 0),
                ],
                // Legacy keys kept for HierarchyTreeNodeResource & HierarchyTreeNode component
                'organization_id' => $org->id,
                'edge_id' => $edgeId,
                'parent_organization_id' => $parentOrgId,
                'name_en' => $org->name_en,
                'name_am' => $org->name_am,
                'organization_type' => $orgType ? [
                    'code' => $orgType->code,
                    'name_en' => $orgType->name_en,
                    'name_am' => $orgType->name_am,
                ] : null,
                'logo_url' => $org->logo_url,
                'depth' => $depth,
                'child_count' => count($allChildren),
                'relationship_type' => $relationshipType,
                'effective_from' => $edgeFrom,
                'effective_to' => $edgeTo,
                'can' => [
                    'edit' => ($user?->can('organization-edges.update') ?? false)
                        && $version->status === HierarchyVersionStatus::Draft
                        && $edgeId !== null,
                    'remove' => ($user?->can('organization-edges.remove') ?? false)
                        && $version->status === HierarchyVersionStatus::Draft
                        && $edgeId !== null,
                    'addChild' => ($user?->can('organization-edges.create') ?? false)
                        && ($user?->can('hierarchy-versions.manageTree') ?? false)
                        && $version->status === HierarchyVersionStatus::Draft,
                ],
            ];
        };

        // ── 9. Assemble root nodes ────────────────────────────────────────────
        return $rootIds
            ->map(fn (string $rootId) => $buildOrgNode($rootId, 0, null, null, null, null, null))
            ->filter(fn (array $n) => $n !== [])
            ->values()
            ->all();
    }
}
