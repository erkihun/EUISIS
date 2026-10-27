<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Organization → units → positions → holder, the shape of EUISIS
 * GetOrganizationFullTreeAction (the detail page's structure and the
 * organogram), plus the organizations below one in the published hierarchy.
 */
final class StructureTree
{
    /** @return array<string, mixed> */
    public function forOrganization(Organization $organization): array
    {
        $units = OrganizationUnit::query()->where('organization_id', $organization->id)->with('unitType:id,code,name_en,name_am')
            ->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'organization_id', 'parent_unit_id', 'organization_unit_type_id', 'unit_type', 'code', 'name_en', 'name_am', 'status']);
        $positions = Position::query()->where('organization_id', $organization->id)->orderBy('job_position_code')
            ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'old_code', 'title_en', 'title_am', 'bpr_name', 'grade_level', 'is_active']);
        $assignments = EmployeeAssignment::query()->where('organization_id', $organization->id)->where('is_current', true)
            ->where('assignment_status', EmployeeAssignment::ACTIVE)->whereIn('position_id', $positions->pluck('id'))
            ->with(['employee' => fn ($q) => $q->select(['id', 'employee_number', 'full_name', 'name_en', 'status'])->with('activeIdCard:id,employee_id,status')])
            ->get(['id', 'employee_id', 'position_id', 'effective_from'])->keyBy('position_id');

        $positionsByUnit = $positions->groupBy(fn (Position $position): string => (string) ($position->organization_unit_id ?? ''));
        $unitsByParent = $units->groupBy(fn (OrganizationUnit $unit): string => (string) ($unit->parent_unit_id ?? 'root'));
        $mapPositions = fn (Collection $items): array => $items->map(fn (Position $position): array => $this->positionNode($position, $assignments->get($position->id)))->values()->all();

        $buildUnits = function (?string $parentId) use (&$buildUnits, $unitsByParent, $positionsByUnit, $mapPositions): array {
            return $unitsByParent->get($parentId ?? 'root', collect())->map(function (OrganizationUnit $unit) use ($buildUnits, $positionsByUnit, $mapPositions): array {
                $children = $buildUnits((string) $unit->id);
                $positions = $mapPositions($positionsByUnit->get((string) $unit->id, collect()));

                return [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name_en' => $unit->name_en,
                    'name_am' => $unit->name_am,
                    'unit_type' => $unit->unitType ? $unit->unitType->only(['code', 'name_en', 'name_am']) : ['code' => $unit->unit_type, 'name_en' => $unit->unit_type, 'name_am' => null],
                    'parent_unit_id' => $unit->parent_unit_id,
                    'status' => $unit->status,
                    'child_units_count' => count($children),
                    'positions_count' => count($positions),
                    'employees_count' => collect($positions)->whereNotNull('assignment')->count(),
                    'positions' => $positions,
                    'children' => $children,
                ];
            })->values()->all();
        };
        $occupied = $assignments->count();

        return [
            'organization' => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'type' => $organization->type ? $organization->type->only(['name_en', 'name_am']) : null,
                'status' => $organization->status,
            ],
            'units' => $buildUnits(null),
            'direct_positions' => $mapPositions($positionsByUnit->get('', collect())),
            'counters' => [
                'units' => $units->count(),
                'positions' => $positions->count(),
                'occupied_positions' => $occupied,
                'vacant_positions' => max(0, $positions->count() - $occupied),
                'employees' => $occupied,
            ],
        ];
    }

    /**
     * Organizations below one in a hierarchy version, with their depth.
     *
     * @return list<array{descendant_organization_id: string, depth: int, code: string|null, name_en: string|null, name_am: string|null}>
     */
    public function descendants(string $organizationId, ?string $versionId): array
    {
        if ($versionId === null) {
            return [];
        }
        $children = OrganizationEdge::query()->where('hierarchy_version_id', $versionId)->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->get(['parent_organization_id', 'child_organization_id'])->groupBy('parent_organization_id');
        $depths = [];
        $queue = [[$organizationId, 0]];
        while ($queue !== []) {
            [$id, $depth] = array_shift($queue);
            foreach ($children->get($id, collect()) as $edge) {
                $child = (string) $edge->child_organization_id;
                if ($child !== $organizationId && ! isset($depths[$child])) {
                    $depths[$child] = $depth + 1;
                    $queue[] = [$child, $depth + 1];
                }
            }
        }
        $organizations = Organization::query()->whereIn('id', array_keys($depths))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        return collect($depths)->map(fn (int $depth, string $id): array => [
            'descendant_organization_id' => $id,
            'depth' => $depth,
            'code' => $organizations->get($id)?->code,
            'name_en' => $organizations->get($id)?->name_en,
            'name_am' => $organizations->get($id)?->name_am,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function positionNode(Position $position, ?EmployeeAssignment $assignment): array
    {
        $employee = $assignment?->employee;

        return [
            'id' => $position->id,
            'code' => $position->job_position_code,
            'old_code' => $position->old_code,
            'name_en' => $position->title_en,
            'name_am' => $position->title_am,
            'bpr_name' => $position->bpr_name,
            'grade_level' => $position->grade_level,
            'status' => $position->is_active ? 'active' : 'inactive',
            'occupancy' => $assignment ? 'occupied' : 'vacant',
            'assignment' => $employee ? [
                'effective_from' => $assignment->effective_from?->toDateString(),
                'employee' => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'name_en' => $employee->name_en,
                    'status' => $employee->status,
                    'photo_url' => null,
                    'has_active_id_card' => $employee->activeIdCard !== null,
                ],
            ] : null,
        ];
    }
}
