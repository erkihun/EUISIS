<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\SupervisorDelegation;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Delegations (ADR 0025): a supervisor with reporting-lines.delegate lends
 * their own actions; HR with reporting-lines.manage records one for any
 * supervisor in scope. Either may revoke what they may create; the delegate
 * themself never creates or extends one.
 */
readonly class SupervisorDelegationPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('reporting-lines.view_any') || $user->can('reporting-lines.manage') || $user->can('reporting-lines.delegate');
    }

    public function view(User $user, SupervisorDelegation $delegation): bool
    {
        if ($this->isOwnEmployee($user, (string) $delegation->delegator_employee_id) || $this->isOwnEmployee($user, (string) $delegation->delegate_employee_id)) {
            return true;
        }

        return ($user->can('reporting-lines.view_any') || $user->can('reporting-lines.manage'))
            && $this->scope->canAccessOrganization($user, (string) $delegation->organization_id);
    }

    /** Create a delegation on behalf of the given delegator. */
    public function createFor(User $user, Employee $delegator): bool
    {
        if ($this->isOwnEmployee($user, (string) $delegator->getKey())) {
            return $user->can('reporting-lines.delegate');
        }

        return $user->can('reporting-lines.manage') && $this->scope->canAccessEmployee($user, $delegator);
    }

    public function revoke(User $user, SupervisorDelegation $delegation): bool
    {
        if ($this->isOwnEmployee($user, (string) $delegation->delegator_employee_id)) {
            return $user->can('reporting-lines.delegate');
        }

        return $user->can('reporting-lines.manage') && $this->scope->canAccessOrganization($user, (string) $delegation->organization_id);
    }

    private function isOwnEmployee(User $user, string $employeeId): bool
    {
        return $user->employee_id !== null && (string) $user->employee_id === $employeeId;
    }
}
