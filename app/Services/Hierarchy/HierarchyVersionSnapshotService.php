<?php

declare(strict_types=1);

namespace App\Services\Hierarchy;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organizational memory: what a hierarchy version looked like when it stopped being
 * in use. Edges survive on their own, but names, codes and staffing are live values
 * that move on, and archived institutions drop out of normal queries. So the
 * structure and its counts are written onto the version once, and never rewritten.
 */
class HierarchyVersionSnapshotService
{
    public function capture(HierarchyVersion $version): void
    {
        if ($version->archive_snapshot !== null) {
            return;
        }

        $version->forceFill(['archive_snapshot' => $this->build($version)])->save();
    }

    /** @return array<string, mixed> */
    public function build(HierarchyVersion $version): array
    {
        $edges = OrganizationEdge::query()->where('hierarchy_version_id', $version->getKey())
            ->get(['parent_organization_id', 'child_organization_id', 'relationship_type']);
        $ids = $edges->pluck('parent_organization_id')->merge($edges->pluck('child_organization_id'))->unique()->values();

        // Archived institutions are history too.
        $organizations = Organization::withTrashed()->with(['type' => fn ($query) => $query->withTrashed()])->whereIn('id', $ids)->get()->keyBy('id');
        $units = $this->countsBy('organization_units', $ids);
        $positions = $this->countsBy('positions', $ids);
        $employees = DB::table('employee_assignments')->whereIn('organization_id', $ids)->where('is_current', true)
            ->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');

        $childrenOf = $edges->groupBy('parent_organization_id');
        $children = $edges->pluck('child_organization_id')->flip();
        $maxDepth = 0;

        $node = function (string $id, ?string $relationship, array $path, int $depth) use (&$node, &$maxDepth, $organizations, $childrenOf, $units, $positions, $employees): ?array {
            $organization = $organizations->get($id);
            if ($organization === null || in_array($id, $path, true)) {
                return null;
            }
            $maxDepth = max($maxDepth, $depth);

            return [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'type_en' => $organization->type?->name_en,
                'type_am' => $organization->type?->name_am,
                'status' => $organization->status?->value ?? $organization->status,
                'relationship' => $relationship,
                'units' => (int) ($units[$id] ?? 0),
                'positions' => (int) ($positions[$id] ?? 0),
                'employees' => (int) ($employees[$id] ?? 0),
                'children' => $childrenOf->get($id, collect())
                    ->map(fn (OrganizationEdge $edge) => $node($edge->child_organization_id, $this->relationshipValue($edge), [...$path, $id], $depth + 1))
                    ->filter()->values()->all(),
            ];
        };

        $roots = $ids->reject(fn (string $id) => $children->has($id))->values();
        $tree = $roots->map(fn (string $id) => $node($id, null, [], 0))->filter()->values()->all();

        return [
            'captured_at' => now()->toIso8601String(),
            'summary' => [
                'organizations' => $ids->count(),
                'relationships' => $edges->count(),
                'roots' => $roots->count(),
                'max_depth' => $maxDepth,
                'units' => (int) $units->sum(),
                'positions' => (int) $positions->sum(),
                'employees' => (int) $employees->sum(),
            ],
            'by_type' => $organizations->groupBy(fn (Organization $organization) => (string) $organization->organization_type_id)
                ->map(fn (Collection $group) => [
                    'name_en' => $group->first()->type?->name_en,
                    'name_am' => $group->first()->type?->name_am,
                    'count' => $group->count(),
                ])->sortByDesc('count')->values()->all(),
            'tree' => $tree,
        ];
    }

    private function relationshipValue(OrganizationEdge $edge): ?string
    {
        $type = $edge->relationship_type;

        return $type instanceof \BackedEnum ? (string) $type->value : ($type === null ? null : (string) $type);
    }

    /** @return Collection<string, int> */
    private function countsBy(string $table, Collection $ids): Collection
    {
        return DB::table($table)->whereIn('organization_id', $ids)->whereNull('deleted_at')
            ->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');
    }
}
