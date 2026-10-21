<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class EmployeePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view') && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }

    /**
     * The photo alone, on the authorized staff photo route. Registry readers
     * see it, and so does card staff: the ID Card Officer and Approver hold
     * no registry access, yet must compare the face on cards and requests.
     * The organization scope applies either way.
     */
    public function viewPhoto(User $user, Employee $employee): bool
    {
        if ($this->view($user, $employee)) {
            return true;
        }

        return ($user->can('id-cards.view') || $user->can('id-cards.viewAny'))
            && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.manage') && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }

    public function transfer(User $user, Employee $employee): bool
    {
        return $this->update($user, $employee);
    }

    public function viewPii(User $user, Employee $employee): bool
    {
        return $user->can('employees.viewPii')
            && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }
}
