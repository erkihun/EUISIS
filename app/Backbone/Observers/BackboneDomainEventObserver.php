<?php

declare(strict_types=1);

namespace App\Backbone\Observers;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Models\Employee;
use App\Models\EmployeeAssignment;

/**
 * Turns employee and assignment writes into backbone domain events, so the
 * backbone never calls a service module: modules listen instead.
 *
 * Bound to Eloquent's created / updated / deleted hooks rather than `saved`:
 * `wasRecentlyCreated` stays true for the life of a model instance, so a
 * later save of a record created in the same request would otherwise be
 * reported as a second creation. `updated` fires only when something changed,
 * with the changed attributes.
 */
final class BackboneDomainEventObserver
{
    public function assignmentCreated(EmployeeAssignment $assignment): void
    {
        event(new AssignmentChanged((string) $assignment->employee_id, (string) $assignment->getKey(), created: true, deleted: false, changedAttributes: []));
    }

    public function assignmentUpdated(EmployeeAssignment $assignment): void
    {
        event(new AssignmentChanged((string) $assignment->employee_id, (string) $assignment->getKey(), created: false, deleted: false, changedAttributes: array_keys($assignment->getChanges())));
    }

    public function assignmentDeleted(EmployeeAssignment $assignment): void
    {
        event(new AssignmentChanged((string) $assignment->employee_id, (string) $assignment->getKey(), created: false, deleted: true, changedAttributes: []));
    }

    public function employeeCreated(Employee $employee): void
    {
        event(new EmployeeChanged((string) $employee->getKey(), created: true, changedAttributes: []));
    }

    public function employeeUpdated(Employee $employee): void
    {
        event(new EmployeeChanged((string) $employee->getKey(), created: false, changedAttributes: array_keys($employee->getChanges())));
    }
}
