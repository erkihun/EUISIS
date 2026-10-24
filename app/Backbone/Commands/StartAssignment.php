<?php

declare(strict_types=1);

namespace App\Backbone\Commands;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Services\Positions\PositionCapacityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Places an employee whose previous placement ended (reinstatement) back in
 * their last position. ADR 0002, decision 2.
 *
 * - Undone on the day it was recorded, the ended assignment is simply opened
 *   again: nothing happened in between, and the audit log keeps the trail.
 * - Later, a new assignment starts on the given date with the same placement;
 *   the ended one keeps its dates and stops being the latest record.
 *
 * Either way the position must still be active and have a free seat, checked
 * under a lock on the position as registration does. An employee whose
 * placement is still open is moved with TransferAssignment instead.
 */
final readonly class StartAssignment
{
    public function __construct(private PositionCapacityService $capacity) {}

    public function execute(Employee $employee, CarbonInterface $startDate, string $reason): EmployeeAssignment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StartAssignment must run inside the caller\'s transaction.');
        }

        $latest = EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        if ($latest === null) {
            throw ValidationException::withMessages(['status' => __('backbone.assignment_no_previous_placement')]);
        }
        if ($latest->assignment_status !== AssignmentStatus::Closed) {
            throw new LogicException('The employee\'s placement is still open; move them with TransferAssignment.');
        }

        $this->assertSeatFree($latest->position_id === null ? null : (string) $latest->position_id);

        $endedOn = $latest->effective_to?->toDateString();
        if ($endedOn === null || $startDate->toDateString() <= $endedOn) {
            // Undone on the day it was recorded (or earlier): reopen, no new history row.
            $latest->update(['assignment_status' => AssignmentStatus::Active, 'effective_to' => null, 'reason' => $reason]);

            return $latest;
        }

        $latest->update(['is_current' => false]);
        $next = EmployeeAssignment::query()->create([
            'employee_id' => $employee->getKey(),
            'organization_id' => $latest->organization_id,
            'organization_unit_id' => $latest->organization_unit_id,
            'position_id' => $latest->position_id,
            'hierarchy_version_id' => $latest->hierarchy_version_id,
            'job_description_id' => $latest->job_description_id,
            'assignment_status' => AssignmentStatus::Active,
            'effective_from' => $startDate->toDateString(),
            'effective_to' => null,
            'is_current' => true,
            'reason' => $reason,
        ]);
        $employee->update(['current_assignment_id' => $next->getKey()]);

        return $next;
    }

    private function assertSeatFree(?string $positionId): void
    {
        if ($positionId === null) {
            return;
        }

        $position = Position::query()->whereKey($positionId)->lockForUpdate()->first();
        if ($position === null || ! $position->is_active) {
            throw ValidationException::withMessages(['status' => __('backbone.assignment_position_inactive')]);
        }
        if (! $this->capacity->hasFreeSlot($positionId)) {
            throw ValidationException::withMessages(['status' => __('backbone.assignment_position_full')]);
        }
    }
}
