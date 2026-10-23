<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Data\AssignmentContext;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Str;

final class EloquentAssignmentContextReader implements AssignmentContextReader
{
    public function currentFor(string $employeeUuid): ?AssignmentContext
    {
        if (! Str::isUuid($employeeUuid)) {
            return null;
        }

        // At most one row: the database allows one current assignment per employee.
        $assignment = EmployeeAssignment::query()
            ->where('employee_id', $employeeUuid)
            ->where('is_current', true)
            ->first();

        return $assignment === null ? null : AssignmentContext::fromModel($assignment);
    }

    public function find(string $assignmentUuid): ?AssignmentContext
    {
        if (! Str::isUuid($assignmentUuid)) {
            return null;
        }

        $assignment = EmployeeAssignment::query()->whereKey($assignmentUuid)->first();

        return $assignment === null ? null : AssignmentContext::fromModel($assignment);
    }
}
