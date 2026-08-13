<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\AssignmentStatus;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Support\Collection;

final readonly class GetOrganizationFullTreeAction
{
    public function execute(Organization $organization): array
    {
        $units = OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->with('unitType:id,code,name_en,name_am')
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get(['id', 'organization_id', 'parent_unit_id', 'organization_unit_type_id', 'unit_type', 'code', 'name_en', 'name_am', 'status']);

        $positions = Position::query()
            ->where('organization_id', $organization->id)
            ->orderBy('job_position_code')
            ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'old_code', 'title_en', 'title_am', 'bpr_name', 'grade_level', 'is_active']);

        $assignments = EmployeeAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->whereIn('position_id', $positions->pluck('id'))
            ->with(['employee' => fn ($query) => $query
                // photo_path backs the photo_url accessor; no other
                // employee columns are loaded.
                ->select(['id', 'employee_number', 'full_name', 'name_en', 'status', 'photo_path'])
                ->with('activeIdCard:id,employee_id,status')])
            ->get(['id', 'employee_id', 'position_id', 'effective_from'])
            ->keyBy('position_id');

        $positionsByUnit = $positions->groupBy('organization_unit_id');
        $unitsByParent = $units->groupBy(fn (OrganizationUnit $unit): string => $unit->parent_unit_id ?? 'root');

        $mapPositions = fn (Collection $items): array => $items
            ->map(fn (Position $position): array => $this->positionNode($position, $assignments->get($position->id)))
            ->values()
            ->all();

        $buildUnits = function (?string $parentId) use (&$buildUnits, $unitsByParent, $positionsByUnit, $mapPositions): array {
            return $unitsByParent->get($parentId ?? 'root', collect())
                ->map(function (OrganizationUnit $unit) use ($buildUnits, $positionsByUnit, $mapPositions): array {
                    $children = $buildUnits($unit->id);
                    $positions = $mapPositions($positionsByUnit->get($unit->id, collect()));

                    return [
                        'id' => $unit->id,
                        'code' => $unit->code,
                        'name_en' => $unit->name_en,
                        'name_am' => $unit->name_am,
                        'unit_type' => $unit->unitType ? ['code' => $unit->unitType->code, 'name_en' => $unit->unitType->name_en, 'name_am' => $unit->unitType->name_am] : ['code' => $unit->unit_type, 'name_en' => $unit->unit_type, 'name_am' => null],
                        'parent_unit_id' => $unit->parent_unit_id,
                        'status' => $unit->status->value,
                        'child_units_count' => count($children),
                        'positions_count' => count($positions),
                        'employees_count' => collect($positions)->whereNotNull('assignment')->count(),
                        'positions' => $positions,
                        'children' => $children,
                    ];
                })
                ->values()
                ->all();
        };

        $occupied = $assignments->count();

        return [
            'organization' => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'type' => $organization->type ? ['name_en' => $organization->type->name_en, 'name_am' => $organization->type->name_am] : null,
                'status' => $organization->status->value,
            ],
            'units' => $buildUnits(null),
            'direct_positions' => $mapPositions($positionsByUnit->get('', $positionsByUnit->get(null, collect()))),
            'counters' => [
                'units' => $units->count(),
                'positions' => $positions->count(),
                'occupied_positions' => $occupied,
                'vacant_positions' => max(0, $positions->count() - $occupied),
                'employees' => $occupied,
            ],
        ];
    }

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
                    'status' => $employee->status->value,
                    // Public-disk URL via the shared accessor; null when unset.
                    'photo_url' => $employee->photo_url,
                    'has_active_id_card' => $employee->activeIdCard !== null,
                ],
            ] : null,
        ];
    }
}
