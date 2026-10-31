<?php

declare(strict_types=1);

namespace Modules\Assessment\Application\Services\Execution;

use Modules\DailyWork\Contracts\DailyWorkReviewers;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who evaluates an employee, for the evaluator types the system can resolve
 * on its own. Never inferred from job titles or from "any user with a
 * manager role":
 *
 *  - SELF: the active account linked to the employee record.
 *  - DIRECT_MANAGER: Addis One records no supervisor on positions, so the
 *    manager comes from the explicit reviewer assignments that already
 *    define line-manager authority (Daily Activity / EPMS). The most
 *    specific assignment covering the employee on the reference date wins:
 *    the employee, then the nearest unit, then the organization. A tie at
 *    that level is ambiguous and resolves to nobody (flagged, not guessed).
 *
 * Peers, subordinates, committees and named employees need a person to
 * choose them; they are assigned through the reassignment workflow.
 */
class AssessmentEvaluatorResolver
{
    public const RESOLVABLE = ['self', 'direct_manager'];

    public function __construct(private readonly DailyWorkReviewers $reviewers) {}

    public function selfUser(Employee $employee): ?User
    {
        return User::query()->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->first();
    }

    public function directManager(Employee $employee, ?EmployeeAssignment $assignment, Carbon $date): ?User
    {
        // The reviewer assignments that define line-manager authority belong to Daily work.
        return $this->reviewers->directManager($employee, $assignment, $date);
    }
}
