<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts;

use App\Models\EmployeeAssignment;
use App\Models\User;

/**
 * An employee's performance (EPMS) as other parts of the application need it
 * (ADR 0017): My Portal's dashboard panel, and the transfer that ends an
 * assignment.
 */
interface EmployeePerformance
{
    /**
     * My Performance at a glance: the current agreement (id, status, cycle,
     * effective_from, effective_to), up to five KPIs with progress, the counts
     * by health, the result once released, and the steps waiting for the
     * employee (actions). Null when EPMS is off, the account may not use it,
     * or the panel cannot be built.
     *
     * @return array<string, mixed>|null
     */
    public function portalSummary(User $user, string $employeeId): ?array;

    /**
     * Closes the agreements of the old assignment when the employee moves to
     * a new one (transfer), recording who did it.
     */
    public function closeForAssignmentChange(EmployeeAssignment $oldAssignment, EmployeeAssignment $newAssignment, User $actor): void;
}
