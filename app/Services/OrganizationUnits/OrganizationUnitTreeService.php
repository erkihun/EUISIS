<?php

declare(strict_types=1);

namespace App\Services\OrganizationUnits;

use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Collection;

class OrganizationUnitTreeService
{
    /**
     * Build a nested tree structure for a given organization.
     *
     * @return array<int, mixed>
     */
    public function buildTree(string $organizationId): array
    {
        $all = OrganizationUnit::query()
            ->forOrganization($organizationId)
            ->withCount('children')
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        // Load unit type names via a separate query to avoid the enum/model naming conflict
        $this->attachUnitTypeLabels($all);

        return $this->nestChildren($all, null, 0);
    }

    /**
     * Build a nested tree that also includes per-node permission flags for the given user.
     *
     * @return array<int, mixed>
     */
    public function buildTreeWithMeta(string $organizationId, ?User $user): array
    {
        $all = OrganizationUnit::query()
            ->forOrganization($organizationId)
            ->withCount('children')
            ->withTrashed()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        // Load unit type names via a separate query to avoid the enum/model naming conflict
        $this->attachUnitTypeLabels($all);

        return $this->nestChildrenWithMeta($all, null, 0, $user);
    }

    /**
     * Attach unit_type_label and unit_type_name_am to each unit by looking up OrganizationUnitType records.
     *
     * @param  Collection<int, OrganizationUnit>  $units
     */
    private function attachUnitTypeLabels(Collection $units): void
    {
        $typeIds = $units->pluck('organization_unit_type_id')->filter()->unique()->values()->all();

        if (empty($typeIds)) {
            return;
        }

        $types = OrganizationUnitType::query()
            ->whereIn('id', $typeIds)
            ->get(['id', 'name_en', 'name_am'])
            ->keyBy('id');

        foreach ($units as $unit) {
            $type = $unit->organization_unit_type_id ? $types->get($unit->organization_unit_type_id) : null;
            $unit->unit_type_label = $type?->name_en;
            $unit->unit_type_name_am = $type?->name_am;
        }
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $all
     * @return array<int, mixed>
     */
    private function nestChildren(Collection $all, ?string $parentId, int $depth): array
    {
        return $all
            ->filter(fn (OrganizationUnit $u) => $u->parent_unit_id === $parentId)
            ->map(fn (OrganizationUnit $u) => array_merge($u->toArray(), [
                'depth' => $depth,
                'has_children' => ($u->children_count ?? 0) > 0,
                'unit_type_label' => $u->unit_type_label ?? null,
                'unit_type_name_am' => $u->unit_type_name_am ?? null,
                'children' => $this->nestChildren($all, $u->id, $depth + 1),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $all
     * @return array<int, mixed>
     */
    private function nestChildrenWithMeta(Collection $all, ?string $parentId, int $depth, ?User $user): array
    {
        return $all
            ->filter(fn (OrganizationUnit $u) => $u->parent_unit_id === $parentId)
            ->map(function (OrganizationUnit $u) use ($all, $depth, $user) {
                $data = $u->toArray();
                $data['depth'] = $depth;
                $data['has_children'] = ($u->children_count ?? 0) > 0;
                $data['unit_type_label'] = $u->unit_type_label ?? null;
                $data['unit_type_name_am'] = $u->unit_type_name_am ?? null;
                $data['is_deleted'] = $u->trashed();
                $data['can'] = [
                    'update' => $user?->can('update', $u) ?? false,
                    'archive' => $user?->can('archive', $u) ?? false,
                    'restore' => $user?->can('restore', $u) ?? false,
                ];
                $data['children'] = $this->nestChildrenWithMeta($all, $u->id, $depth + 1, $user);

                return $data;
            })
            ->values()
            ->all();
    }

    /**
     * Get all ancestors of a unit (nearest first).
     *
     * @return Collection<int, OrganizationUnit>
     */
    public function getAncestors(OrganizationUnit $unit): Collection
    {
        $ancestors = collect();
        $current = $unit->parent;

        while ($current !== null) {
            $ancestors->push($current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Get all descendants of a unit.
     *
     * @return Collection<int, OrganizationUnit>
     */
    public function getDescendants(OrganizationUnit $unit): Collection
    {
        $descendants = collect();

        $unit->load('children');

        foreach ($unit->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($this->getDescendants($child));
        }

        return $descendants;
    }

    /**
     * Check if $unit is a descendant of $potentialAncestorId.
     */
    public function isDescendantOf(?OrganizationUnit $unit, string $potentialAncestorId): bool
    {
        if ($unit === null) {
            return false;
        }

        $current = $unit;

        while ($current !== null) {
            if ($current->getKey() === $potentialAncestorId) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }

    /**
     * Return a flat list of units for a given organization with indentation depth info.
     *
     * @return array<int, array{id: string, name_en: string, name_am: string|null, code: string, depth: int}>
     */
    public function optionsForOrganization(string $organizationId): array
    {
        $all = OrganizationUnit::query()
            ->active()
            ->forOrganization($organizationId)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        return $this->flattenWithDepth($all, null, 0);
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $all
     * @return array<int, array{id: string, name_en: string, name_am: string|null, code: string, depth: int}>
     */
    private function flattenWithDepth(Collection $all, ?string $parentId, int $depth): array
    {
        $result = [];

        foreach ($all->filter(fn (OrganizationUnit $u) => $u->parent_unit_id === $parentId) as $unit) {
            $result[] = [
                'id' => $unit->id,
                'name_en' => $unit->name_en,
                'name_am' => $unit->name_am,
                'code' => $unit->code,
                'depth' => $depth,
            ];

            foreach ($this->flattenWithDepth($all, $unit->id, $depth + 1) as $child) {
                $result[] = $child;
            }
        }

        return $result;
    }
}
