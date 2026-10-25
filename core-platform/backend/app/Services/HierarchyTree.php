<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A hierarchy version as the node tree of EUISIS HierarchyTreeService
 * (organizations, their child organizations by edge, then their units),
 * cut to the reader's organizations. Read only: the Core does not edit
 * versions (ADR 0006), so no node offers edit, remove or add.
 */
final class HierarchyTree
{
    public function __construct(private readonly OrganizationScope $scope) {}

    /** @return list<array<string, mixed>> */
    public function build(HierarchyVersion $version, User $user, bool $includeUnits = true, bool $includeInactive = false): array
    {
        $edges = OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
            ->get(['id', 'parent_organization_id', 'child_organization_id', 'relationship_type', 'effective_from', 'effective_to']);
        if ($edges->isEmpty()) {
            return [];
        }
        $orgIds = $edges->pluck('parent_organization_id')->merge($edges->pluck('child_organization_id'))->map(fn ($id): string => (string) $id)->unique()->values();
        $allowed = $this->scope->organizationIds($user);
        if ($allowed !== null) {
            $orgIds = $orgIds->intersect($allowed)->values();
        }
        if ($orgIds->isEmpty()) {
            return [];
        }

        $organizations = Organization::query()->whereIn('id', $orgIds)->with('type:id,code,name_en,name_am')->get()->keyBy('id');
        $units = ! $includeUnits ? collect() : OrganizationUnit::query()->whereIn('organization_id', $orgIds)
            ->when(! $includeInactive, fn ($q) => $q->where('status', 'active'))->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'organization_id', 'parent_unit_id', 'organization_unit_type_id', 'code', 'name_en', 'name_am', 'status', 'sort_order']);
        $unitTypes = OrganizationUnitType::withTrashed()->whereIn('id', $units->pluck('organization_unit_type_id')->filter()->unique())->get(['id', 'name_en', 'name_am'])->keyBy('id');
        $unitsByOrg = $units->groupBy('organization_id');
        $count = fn (string $table, string $column, array $ids, bool $current) => DB::table($table)->whereIn($column, $ids)
            ->when($table === 'positions', fn ($q) => $q->whereNull('deleted_at'))->when($current, fn ($q) => $q->where('is_current', true))
            ->groupBy($column)->selectRaw("{$column} as k, count(*) as c")->pluck('c', 'k')->map(fn ($c): int => (int) $c);
        $positionsByOrg = $count('positions', 'organization_id', $orgIds->all(), false);
        $employeesByOrg = $count('employee_assignments', 'organization_id', $orgIds->all(), true);
        $positionsByUnit = $units->isEmpty() ? collect() : $count('positions', 'organization_unit_id', $units->pluck('id')->all(), false);
        $employeesByUnit = $units->isEmpty() ? collect() : $count('employee_assignments', 'organization_unit_id', $units->pluck('id')->all(), true);
        $rootUnitCount = $units->whereNull('parent_unit_id')->groupBy('organization_id')->map->count();

        $rootIds = $orgIds->diff($edges->pluck('child_organization_id')->map(fn ($id): string => (string) $id))->values();
        $childrenByParent = $edges->groupBy(fn (OrganizationEdge $edge): string => (string) $edge->parent_organization_id);
        $none = ['edit' => false, 'remove' => false, 'addChild' => false];

        $unitNode = function (OrganizationUnit $unit, int $depth) use (&$unitNode, $units, $unitTypes, $positionsByUnit, $employeesByUnit, $none): array {
            $type = $unitTypes->get($unit->organization_unit_type_id);
            $children = $units->filter(fn (OrganizationUnit $u): bool => $u->parent_unit_id === $unit->id)->map(fn (OrganizationUnit $child): array => $unitNode($child, $depth + 1))->values()->all();

            return [
                'id' => $unit->id, 'type' => 'organization_unit', 'label' => $this->name($unit->name_en, $unit->name_am), 'code' => $unit->code,
                'status' => $unit->status, 'status_label' => __('hierarchy-versions.'.$unit->status),
                'node_type_label' => $this->name($type?->name_en, $type?->name_am) ?: null, 'node_type_label_am' => $type?->name_am,
                'children' => $children,
                'meta' => ['organization_unit_type' => $type?->name_en, 'organization_unit_type_am' => $type?->name_am, 'position_count' => $positionsByUnit->get($unit->id, 0), 'employee_count' => $employeesByUnit->get($unit->id, 0), 'functional_reports_to' => []],
                'organization_id' => $unit->id, 'edge_id' => null, 'parent_organization_id' => null, 'name_en' => $unit->name_en, 'name_am' => $unit->name_am,
                'organization_type' => $type ? ['code' => null, 'name_en' => $type->name_en, 'name_am' => $type->name_am] : null,
                'logo_url' => null, 'depth' => $depth, 'child_count' => count($children), 'relationship_type' => null, 'effective_from' => null, 'effective_to' => null, 'can' => $none,
            ];
        };

        $orgNode = function (string $id, int $depth, ?OrganizationEdge $edge, array $path) use (&$orgNode, $organizations, $childrenByParent, $unitsByOrg, $positionsByOrg, $employeesByOrg, $rootUnitCount, $unitNode, $none): array {
            $organization = $organizations->get($id);
            if ($organization === null || in_array($id, $path, true)) {
                return [];
            }
            $children = $childrenByParent->get($id, collect())
                ->map(fn (OrganizationEdge $child): array => $orgNode((string) $child->child_organization_id, $depth + 1, $child, [...$path, $id]))
                ->filter()->values()->all();
            foreach ($unitsByOrg->get($id, collect())->whereNull('parent_unit_id') as $unit) {
                $children[] = $unitNode($unit, $depth + 1);
            }
            $type = $organization->type;

            return [
                'id' => $organization->id, 'type' => 'organization', 'label' => $this->name($organization->name_en, $organization->name_am), 'code' => $organization->code,
                'status' => $organization->status, 'status_label' => __('hierarchy-versions.'.$organization->status),
                'node_type_label' => $this->name($type?->name_en, $type?->name_am) ?: null, 'node_type_label_am' => $type?->name_am,
                'children' => $children,
                'meta' => ['organization_type' => $type?->name_en, 'organization_type_am' => $type?->name_am, 'organization_unit_count' => $rootUnitCount->get($id, 0), 'position_count' => $positionsByOrg->get($id, 0), 'employee_count' => $employeesByOrg->get($id, 0)],
                'organization_id' => $organization->id, 'edge_id' => $edge?->id, 'parent_organization_id' => $edge?->parent_organization_id,
                'name_en' => $organization->name_en, 'name_am' => $organization->name_am,
                'organization_type' => $type ? $type->only(['code', 'name_en', 'name_am']) : null,
                'logo_url' => null, 'depth' => $depth, 'child_count' => count($children),
                'relationship_type' => $edge?->relationship_type, 'effective_from' => $edge?->effective_from?->toDateString(), 'effective_to' => $edge?->effective_to?->toDateString(),
                'can' => $none,
            ];
        };

        return $rootIds->map(fn (string $id): array => $orgNode($id, 0, null, []))->filter()->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return array{total_organizations: int, total_relations: int, root_nodes: int, max_depth: int}
     */
    public function summary(array $tree): array
    {
        $summary = ['total_organizations' => 0, 'total_relations' => 0, 'root_nodes' => count($tree), 'max_depth' => 0];
        $walk = function (array $nodes) use (&$walk, &$summary): void {
            foreach ($nodes as $node) {
                $summary['total_organizations']++;
                $summary['max_depth'] = max($summary['max_depth'], (int) $node['depth']);
                if ($node['edge_id'] !== null) {
                    $summary['total_relations']++;
                }
                $walk($node['children']);
            }
        };
        $walk($tree);

        return $summary;
    }

    /**
     * The version's edges; for a reader, only those with both ends inside their scope (ADR 0008).
     *
     * @return list<array<string, mixed>>
     */
    public function edges(HierarchyVersion $version, ?User $reader = null): array
    {
        $allowed = $reader === null ? null : $this->scope->organizationIds($reader);
        $edges = OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('parent_organization_id', $allowed)->whereIn('child_organization_id', $allowed))
            ->orderBy('parent_organization_id')->orderBy('child_organization_id')->get();
        $organizations = Organization::query()->whereIn('id', $edges->pluck('parent_organization_id')->merge($edges->pluck('child_organization_id'))->unique())
            ->with('type:id,code,name_en,name_am')->get(['id', 'organization_type_id', 'name_en', 'name_am', 'code', 'status'])->keyBy('id');
        $side = fn (?Organization $organization): ?array => $organization === null ? null : $organization->only(['id', 'code', 'name_en', 'name_am', 'status'])
            + ['logo_url' => null, 'type' => $organization->type?->only(['code', 'name_en', 'name_am'])];

        return $edges->map(fn (OrganizationEdge $edge): array => [
            'id' => $edge->id,
            'hierarchy_version_id' => $edge->hierarchy_version_id,
            'parent_organization_id' => $edge->parent_organization_id,
            'child_organization_id' => $edge->child_organization_id,
            'relationship_type' => $edge->relationship_type,
            'effective_from' => $edge->effective_from?->toDateString(),
            'effective_to' => $edge->effective_to?->toDateString(),
            'parent_organization' => $side($organizations->get($edge->parent_organization_id)),
            'child_organization' => $side($organizations->get($edge->child_organization_id)),
            'can' => ['view' => true, 'update' => false, 'remove' => false],
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function version(HierarchyVersion $version): array
    {
        $edges = OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->count();
        $approver = $version->approved_by === null ? null : User::query()->find($version->approved_by, ['id', 'name'])?->only(['id', 'name']);

        return [
            'id' => $version->id,
            'version_name' => $version->version_name,
            'notes' => $version->notes,
            'source_document' => $version->source_document,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'approval_date' => $version->approval_date?->format('Y-m-d H:i'),
            'published_at' => $version->approval_date?->format('Y-m-d H:i'),
            'effective_from_display' => $version->effective_from?->toDateString(),
            'effective_to_display' => $version->effective_to?->toDateString(),
            'approval_date_display' => $version->approval_date?->format('Y-m-d H:i'),
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
            'edges_count' => $edges,
            'approver' => $approver,
            'published_by' => $approver,
            'can' => ['view' => true, 'update' => false, 'archive' => false, 'publish' => false, 'manageTree' => false],
        ];
    }

    private function name(?string $en, ?string $am): string
    {
        return app()->getLocale() === 'am' && filled($am) ? (string) $am : (string) ($en ?? $am ?? '');
    }
}
