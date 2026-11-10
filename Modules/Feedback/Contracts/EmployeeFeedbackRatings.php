<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use App\Models\User;
use Modules\Feedback\Contracts\Data\EmployeeFeedbackRatingSummary;

/**
 * Clients' star ratings of one employee (ADR 0017), for the employee profile.
 */
interface EmployeeFeedbackRatings
{
    /**
     * The ratings the viewer may count about this employee: the same
     * permission, target-type grant and organization scope the feedback
     * screens apply, so the average never counts a rating the viewer could
     * not open. Null when the viewer may not read the employee's ratings at
     * all, including the employee viewing their own record.
     *
     * Takes the employee's key, not the Core model, so the module keeps no
     * direct Golden Path model import (ServiceModuleCoreDependencyTest).
     */
    public function summaryFor(int|string $employeeId, User $viewer): ?EmployeeFeedbackRatingSummary;
}
