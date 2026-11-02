<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\DailyWork\Contracts\Data\DailyActivityCoverage;
use Modules\DailyWork\Contracts\Data\ManagerResolution;

/**
 * Who a user reviews (ADR 0017): the organizations, units and employees their
 * daily-work reviewer assignments cover. Performance and Field work scope
 * their team views with it.
 */
interface DailyWorkReviewers
{
    public function coverage(User $user): DailyActivityCoverage;

    /**
     * The employee's line manager on the date, from the reviewer assignments
     * (Addis One records no supervisor on positions): the most specific active
     * assignment covering the employee wins — the employee, then the nearest
     * unit up the tree, then the organization. A tie at that level is ambiguous
     * and resolves to nobody (flagged, not guessed); so does no assignment.
     */
    public function directManager(Employee $employee, ?EmployeeAssignment $assignment, Carbon $date): ?User;

    /**
     * The same answer with its basis (ADR 0025). With structural reporting
     * enabled (config features.structural_reporting) the organization
     * structure — unit heads, published position profiles, acting
     * appointments — is asked first and the reviewer assignments are the
     * fallback when it names nobody; otherwise only the reviewer assignments
     * are consulted, exactly as before.
     */
    public function directManagerResolution(Employee $employee, ?EmployeeAssignment $assignment, Carbon $date): ManagerResolution;
}
