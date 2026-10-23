<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class CardRequestPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('id-cards.viewAny') || $user->can('cards.manage');
    }

    public function view(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.view') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    public function create(User $user): bool
    {
        return $user->can('id-cards.submitRequest') || $user->can('cards.manage');
    }

    public function submit(User $user): bool
    {
        return $user->can('id-cards.submitRequest') || $user->can('cards.manage');
    }

    /**
     * Request a card for this employee. Card officers need the request grant
     * and the employee in their scope, not the employee registry
     * (employees.view): the ID Card Officer role has no registry access.
     */
    public function requestFor(User $user, Employee $employee): bool
    {
        return $this->submit($user)
            && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }

    public function verify(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.verifyRequest') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    /**
     * Approval is the approver's decision alone (go-live UAT F3: "a different
     * approver approves"): cards.manage does not stand in for it, and nobody
     * approves a request they submitted themselves.
     */
    public function approve(User $user, CardRequest $cardRequest): bool
    {
        return $user->can('id-cards.approveRequest')
            && ! $cardRequest->isRequestedBy($user)
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    public function reject(User $user, CardRequest $cardRequest): bool
    {
        return $user->can('id-cards.rejectRequest')
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    public function cancel(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.submitRequest') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    /** Legacy fallback used by existing controller authorize() calls */
    public function update(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.verifyRequest') || $user->can('id-cards.approveRequest') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }
}
