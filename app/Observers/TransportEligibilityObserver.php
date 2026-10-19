<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Transport\ReevaluateTransportEligibility;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Support\Features;

/**
 * Transport reacts to the canonical employee records (there is no separate
 * transport employee master): a placement change or a status change queues
 * a re-evaluation of the employee's route assignments after commit.
 */
class TransportEligibilityObserver
{
    private const PLACEMENT = ['organization_id', 'organization_unit_id', 'assignment_status', 'is_current', 'effective_to'];

    public function saved(EmployeeAssignment $assignment): void
    {
        if (Features::enabled('transport') && ($assignment->wasRecentlyCreated || $assignment->wasChanged(self::PLACEMENT))) {
            ReevaluateTransportEligibility::dispatch((string) $assignment->employee_id)->afterCommit();
        }
    }

    public function employeeSaved(Employee $employee): void
    {
        if (Features::enabled('transport') && ! $employee->wasRecentlyCreated && $employee->wasChanged(['current_assignment_id', 'status'])) {
            ReevaluateTransportEligibility::dispatch((string) $employee->id)->afterCommit();
        }
    }
}
