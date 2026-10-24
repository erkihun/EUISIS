<?php

declare(strict_types=1);

namespace App\Backbone\Commands;

use App\Models\EmployeeAssignment;

/**
 * Points the current assignments of a position at the job description now in
 * force for it. The only backbone write the job description publish step needs;
 * nothing else about the assignment changes.
 */
final class StampJobDescriptionOnCurrentAssignments
{
    /** @return int the number of assignments stamped */
    public function execute(string $positionId, string $jobDescriptionId): int
    {
        return EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->update(['job_description_id' => $jobDescriptionId]);
    }
}
