<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Jobs\Communication\SyncEmployeeCommunicationMemberships;
use Illuminate\Support\Facades\DB;

/**
 * Keeps automatic channel membership in step with assignments: a change to
 * where someone is placed, or to which assignment is current, queues a sync
 * for that employee after the transaction commits. Listens to backbone
 * events; the backbone does not call this module.
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
class CommunicationMembershipSync
{
    private const PLACEMENT = ['organization_id', 'organization_unit_id', 'assignment_status', 'is_current', 'effective_to'];

    /** @var array<string, true> employees whose sync waits for the current transaction to commit */
    private array $waiting = [];

    public function onAssignmentChanged(AssignmentChanged $event): void
    {
        if ($event->created || $event->deleted || $event->changedAny(self::PLACEMENT)) {
            $this->queue($event->employeeId);
        }
    }

    /** Employee saves: the current assignment or the employee's status moved. */
    public function onEmployeeChanged(EmployeeChanged $event): void
    {
        if ($event->changedAny(['current_assignment_id', 'status'])) {
            $this->queue($event->employeeId);
        }
    }

    /** Queue a membership sync for the employee once the current transaction (if any) commits. */
    public function queue(mixed $employeeId): void
    {
        if ($employeeId === null || $employeeId === '') {
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
