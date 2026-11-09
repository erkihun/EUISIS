<?php

declare(strict_types=1);

namespace App\Backbone\Commands;

use App\Enums\AssignmentStatus;
use App\Models\EmployeeAssignment;

/**
 * Points the active current assignments of a position at the job description now
 * in force for it. The only backbone write the job description publish step needs;
 * nothing else about the assignment changes.
 *
 * A closed current row belongs to an employee whose employment ended (ADR 0002);
 * it keeps the job description it had. Rows are saved one by one so each change
 * records assignment.updated in the caller's transaction.
 */
final class StampJobDescriptionOnCurrentAssignments
{
    /** @return int the number of assignments stamped */
    public function execute(string $positionId, string $jobDescriptionId): int
    {
        $stamped = 0;

        EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->chunkById(100, function ($assignments) use ($jobDescriptionId, &$stamped): void {
                foreach ($assignments as $assignment) {
                    $assignment->update(['job_description_id' => $jobDescriptionId]);
                    $stamped++;
                }
            });

        return $stamped;
    }
}
