<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\EmployeeAssignment;

/** Where an employee works: the assignment and the organization, unit and position it places them in. */
final readonly class AssignmentContext
{
    public function __construct(
        public string $assignmentUuid,
        public string $employeeUuid,
        public ?string $organizationUuid,
        public ?string $organizationUnitUuid,
        public ?string $positionUuid,
        public ?string $hierarchyVersionUuid,
        public string $status,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public bool $isCurrent,
    ) {}

    public static function fromModel(EmployeeAssignment $assignment): self
    {
        return new self(
            assignmentUuid: (string) $assignment->getKey(),
            employeeUuid: (string) $assignment->employee_id,
            organizationUuid: $assignment->organization_id === null ? null : (string) $assignment->organization_id,
            organizationUnitUuid: $assignment->organization_unit_id === null ? null : (string) $assignment->organization_unit_id,
            positionUuid: $assignment->position_id === null ? null : (string) $assignment->position_id,
            hierarchyVersionUuid: $assignment->hierarchy_version_id === null ? null : (string) $assignment->hierarchy_version_id,
            status: $assignment->assignment_status instanceof \BackedEnum ? $assignment->assignment_status->value : (string) $assignment->assignment_status,
            effectiveFrom: $assignment->effective_from?->toDateString(),
            effectiveTo: $assignment->effective_to?->toDateString(),
            isCurrent: (bool) $assignment->is_current,
        );
    }
}
