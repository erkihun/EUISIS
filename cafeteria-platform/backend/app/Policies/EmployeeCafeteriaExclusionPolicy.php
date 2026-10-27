<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeCafeteriaExclusion;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Exclusions record leave / suspension reasons: every per-record action is
 * limited to employees inside the actor's organization scope.
 */
readonly class EmployeeCafeteriaExclusionPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_employee_exclusions.view');
    }

    public function view(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        return $user->can('cafeteria_employee_exclusions.view') && $this->inScope($user, $exclusion);
    }

    public function create(User $user): bool
    {
        return $user->can('cafeteria_employee_exclusions.create');
    }

    public function update(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        return $user->can('cafeteria_employee_exclusions.update') && $this->inScope($user, $exclusion);
    }

    public function end(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        return $user->can('cafeteria_employee_exclusions.end') && $this->inScope($user, $exclusion);
    }

    public function archive(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        return $user->can('cafeteria_employee_exclusions.archive') && $this->inScope($user, $exclusion);
    }

    public function restore(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        // No restore route exists for exclusions; archiving is final here.
        return false;
    }

    private function inScope(User $user, EmployeeCafeteriaExclusion $exclusion): bool
    {
        $employee = $exclusion->employee;

        return $employee !== null && $this->scope->canAccessEmployee($user, $employee);
    }
}
