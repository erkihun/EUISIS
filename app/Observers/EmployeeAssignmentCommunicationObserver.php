<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Communication\SyncEmployeeCommunicationMemberships;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Keeps automatic channel membership in step with assignments: a change to
 * where someone is placed, or to which assignment is current, queues a sync
 * for that employee after the transaction commits.
 *
 * The dispatch itself waits for the commit, not only the push: the job is
 * ShouldBeUnique, and its lock is taken when it is dispatched. With the
 * database cache store that lock is an INSERT into cache_locks on the same
 * connection; a second dispatch for the employee in one transaction (the
 * assignment, then current_assignment_id) hit the existing key, and on
 * PostgreSQL the failed INSERT aborted the whole transaction. Outside a
 * transaction DB::afterCommit() runs the dispatch at once.
 *
 * One sync per employee per transaction: later saves in the same
 * transaction join the waiting one. Bound as a scoped singleton, so the
 * waiting set never outlives the request or queue job that filled it.
 */
class EmployeeAssignmentCommunicationObserver
{
    private const PLACEMENT = ['organization_id', 'organization_unit_id', 'assignment_status', 'is_current', 'effective_to'];

    /** @var array<string, true> employees whose sync waits for the current transaction to commit */
    private array $waiting = [];

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

    /** Queue a membership sync for the employee once the current transaction (if any) commits. */
    public function queue(mixed $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $employeeId = (string) $employeeId;
        if (isset($this->waiting[$employeeId])) {
            return;
        }

        $this->waiting[$employeeId] = true;
        DB::afterRollBack(function () use ($employeeId): void {
            unset($this->waiting[$employeeId]);
        });
        DB::afterCommit(function () use ($employeeId): void {
            unset($this->waiting[$employeeId]);
            SyncEmployeeCommunicationMemberships::dispatch($employeeId);
        });
    }
}
