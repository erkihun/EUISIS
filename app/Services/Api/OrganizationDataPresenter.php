<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use BackedEnum;

/**
 * The single place where organization-to-employee records are turned into
 * external API payloads.
 *
 * Every organization/unit/position/employee body returned by the v1 integration
 * API is built here. Centralising it is the point: a reviewer can confirm the
 * safe-field rule by reading one file, and a new column added to `employees`
 * cannot leak by being picked up automatically — nothing here iterates over a
 * model's attributes, each field is named explicitly.
 *
 * Never returned, for any scope: national_id, phone, email, address, salary,
 * documents, photo/signature paths, private notes, or password/security data.
 */
class OrganizationDataPresenter
{
    /**
     * Employee columns safe to select. Listed so a query cannot accidentally
     * hydrate a sensitive attribute that a later change might serialise.
     *
     * @var array<int, string>
     */
    public const EMPLOYEE_COLUMNS = [
        'id', 'employee_number', 'full_name', 'name_en', 'first_name', 'middle_name',
        'last_name', 'gender', 'status', 'current_assignment_id', 'updated_at',
    ];

    /** @return array<string, mixed> */
    public function organization(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'organization_type' => $organization->relationLoaded('type') && $organization->type !== null
                ? [
                    'code' => $organization->type->code,
                    'name_en' => $organization->type->name_en,
                    'name_am' => $organization->type->name_am,
                ]
                : null,
            'status' => $this->scalar($organization->status),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function unit(OrganizationUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name_en' => $unit->name_en,
            'name_am' => $unit->name_am,
            'unit_type' => $unit->unit_type,
            'parent_unit_id' => $unit->parent_unit_id,
            'organization_id' => $unit->organization_id,
            'status' => $this->scalar($unit->status),
            'updated_at' => $unit->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Position payload.
     *
     * The published field names follow the integration contract, not the
     * column names: `standard_name`, `job_grade` and `position_status` are the
     * agreed external vocabulary for what the schema stores as `title_en`,
     * `grade_level` and `is_active`.
     *
     * `occupied` is read from the eager-loaded current-assignment count when
     * present, so a list of positions costs one extra query rather than one per
     * row.
     *
     * @return array<string, mixed>
     */
    public function position(Position $position): array
    {
        $occupied = $position->current_assignments_count !== null
            ? $position->current_assignments_count > 0
            : $position->assignments()->where('is_current', true)->exists();

        return [
            'id' => $position->id,
            'code' => $position->job_position_code ?? $position->code,
            'standard_name' => $position->title_en,
            'standard_name_am' => $position->title_am,
            'bpr_name' => $position->bpr_name,
            'job_grade' => $position->grade_level,
            'position_status' => $position->is_active ? 'active' : 'inactive',
            'organization_id' => $position->organization_id,
            'organization_unit_id' => $position->organization_unit_id,
            'occupied' => $occupied,
            'vacant' => ! $occupied,
            'updated_at' => $position->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Employee payload.
     *
     * Gender is included only when the caller holds a scope that covers
     * employee records at all; it is dropped from the nested structure summary,
     * which exists to show shape rather than describe people.
     *
     * @return array<string, mixed>
     */
    public function employee(Employee $employee, bool $includeGender = true): array
    {
        $assignment = $employee->relationLoaded('currentAssignment')
            ? $employee->currentAssignment
            : null;

        $payload = [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name ?? trim(implode(' ', array_filter([
                $employee->first_name, $employee->middle_name, $employee->last_name,
            ]))),
            'full_name_en' => $employee->name_en,
            'employment_status' => $this->scalar($employee->status),
            'organization_id' => $assignment?->organization_id,
            'organization_unit_id' => $assignment?->organization_unit_id,
            'position_id' => $assignment?->position_id,
            'active_assignment' => $assignment === null ? null : $this->assignment($assignment),
            'updated_at' => $employee->updated_at?->toIso8601String(),
        ];

        if ($includeGender) {
            $payload['gender'] = $this->scalar($employee->gender);
        }

        return $payload;
    }

    /**
     * Assignment payload: where an employee sits, and since when.
     *
     * `reason` is deliberately omitted — it is free text an HR officer writes
     * about a person and is not part of the published structure contract.
     *
     * @return array<string, mixed>
     */
    public function assignment(EmployeeAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'organization_id' => $assignment->organization_id,
            'organization_unit_id' => $assignment->organization_unit_id,
            'position_id' => $assignment->position_id,
            'assignment_status' => $this->scalar($assignment->assignment_status),
            'is_current' => (bool) $assignment->is_current,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
        ];
    }

    /**
     * Minimal employee descriptor for the nested structure response, where the
     * question is "is this seat filled, and by whom" rather than "tell me about
     * this person".
     *
     * @return array<string, mixed>
     */
    public function employeeSummary(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'employment_status' => $this->scalar($employee->status),
        ];
    }

    /** Enum-or-string columns are published as their plain string value. */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
