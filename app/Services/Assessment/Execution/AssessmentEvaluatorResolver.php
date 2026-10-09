<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who evaluates an employee, for the evaluator types the system can resolve
 * on its own. Never inferred from job titles or from "any user with a
 * manager role":
 *
 *  - SELF: the active account linked to the employee record.
 *  - DIRECT_MANAGER: EUISIS records no supervisor on positions, so the
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

    public function selfUser(Employee $employee): ?User
    {
        return User::query()->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->first();
    }

    public function directManager(Employee $employee, ?EmployeeAssignment $assignment, Carbon $date): ?User
    {
        if ($assignment === null) {
            return null;
        }
        $day = $date->toDateString();
        $candidates = DailyActivityReviewerAssignment::query()
            ->where('is_active', true)
            ->where('organization_id', $assignment->organization_id)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->whereHas('reviewer', fn ($q) => $q->where('status', 'active'))
            ->get(['reviewer_user_id', 'organization_unit_id', 'include_sub_units', 'employee_id']);
        $selfUserIds = User::query()->where('employee_id', $employee->id)->pluck('id')->all();
        $candidates = $candidates->reject(fn ($c) => in_array($c->reviewer_user_id, $selfUserIds, true));

        // 1. Assigned to this employee.
        $direct = $candidates->where('employee_id', $employee->id)->pluck('reviewer_user_id')->unique();
        if ($direct->isNotEmpty()) {
            return $direct->count() === 1 ? User::query()->find($direct->first()) : null;
        }

        // 2. Nearest unit up the tree.
        $unitLevel = $candidates->whereNull('employee_id')->whereNotNull('organization_unit_id');
        $chain = $assignment->organization_unit_id ? [$assignment->organization_unit_id, ...$this->ancestors($assignment->organization_unit_id)] : [];
        foreach ($chain as $depth => $unitId) {
            $matches = $unitLevel->filter(fn ($c) => $c->organization_unit_id === $unitId && ($depth === 0 || $c->include_sub_units))->pluck('reviewer_user_id')->unique();
            if ($matches->isNotEmpty()) {
                return $matches->count() === 1 ? User::query()->find($matches->first()) : null;
            }
        }

        // 3. The whole organization.
        $orgLevel = $candidates->whereNull('employee_id')->whereNull('organization_unit_id')->pluck('reviewer_user_id')->unique();

        return $orgLevel->count() === 1 ? User::query()->find($orgLevel->first()) : null;
    }

    /** @return array<int, string> */
    private function ancestors(string $unitId): array
    {
        $ids = [];
        $current = OrganizationUnit::query()->whereKey($unitId)->value('parent_unit_id');
        for ($guard = 0; $current !== null && $guard < 50; $guard++) {
            $ids[] = $current;
            $current = OrganizationUnit::query()->whereKey($current)->value('parent_unit_id');
        }

        return $ids;
    }
}
