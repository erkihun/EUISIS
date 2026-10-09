<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardRequest;
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

    public function verify(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.verifyRequest') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    public function approve(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.approveRequest') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $cardRequest->employee);
    }

    public function reject(User $user, CardRequest $cardRequest): bool
    {
        return ($user->can('id-cards.rejectRequest') || $user->can('cards.manage'))
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
