<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Communication\SyncEmployeeCommunicationMemberships;
use App\Models\Employee;
use App\Models\EmployeeAssignment;

/**
 * Keeps automatic channel membership in step with assignments: a change to
 * where someone is placed, or to which assignment is current, queues a sync
 * for that employee after the transaction commits.
 */
class EmployeeAssignmentCommunicationObserver
{
    private const PLACEMENT = ['organization_id', 'organization_unit_id', 'assignment_status', 'is_current', 'effective_to'];

    public function saved(EmployeeAssignment $assignment): void
    {
        if ($assignment->wasRecentlyCreated || $assignment->wasChanged(self::PLACEMENT)) {
            $this->queue($assignment->employee_id);
        }
    }

    public function deleted(EmployeeAssignment $assignment): void
    {
        $this->queue($assignment->employee_id);
    }

    /** Employee saves: the current assignment or the employee's status moved. */
    public function employeeSaved(Employee $employee): void
    {
        if ($employee->wasChanged(['current_assignment_id', 'status'])) {
            $this->queue($employee->getKey());
        }
    }

    private function queue(mixed $employeeId): void
    {
        if ($employeeId !== null) {
            SyncEmployeeCommunicationMemberships::dispatch((string) $employeeId)->afterCommit();
        }
    }
}
