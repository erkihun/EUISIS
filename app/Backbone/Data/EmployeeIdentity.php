<?php

declare(strict_types=1);

namespace App\Backbone\Data;

use App\Models\Employee;

/**
 * Basic identity of one employee as the backbone publishes it to other modules.
 *
 * The employee UUID is the permanent machine identifier and survives transfers,
 * restructuring and card replacement. The employee number is the business
 * identifier people read. Neither is a user account id or a card identifier,
 * and none of them is interchangeable with another.
 */
final readonly class EmployeeIdentity
{
    public function __construct(
        public string $employeeUuid,
        public string $employeeNumber,
        public ?string $fullName,
        public ?string $nameEn,
        public string $status,
        public ?string $currentAssignmentUuid,
    ) {}

    public static function fromModel(Employee $employee): self
    {
        return new self(
            employeeUuid: (string) $employee->getKey(),
            employeeNumber: (string) $employee->employee_number,
            fullName: $employee->full_name,
            nameEn: $employee->name_en,
            status: $employee->status->value,
            currentAssignmentUuid: $employee->current_assignment_id === null ? null : (string) $employee->current_assignment_id,
        );
    }
}
