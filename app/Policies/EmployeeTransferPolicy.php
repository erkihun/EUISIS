<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TransferStatus;
use App\Models\EmployeeTransfer;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class EmployeeTransferPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('transfers.viewAny');
    }

    public function view(User $user, EmployeeTransfer $transfer): bool
    {
        if (! $user->can('transfers.view')) {
            return false;
        }

        return $this->organizationScopeService->canAccessOrganization($user, $transfer->from_organization_id)
            || $this->organizationScopeService->canAccessOrganization($user, $transfer->to_organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('transfers.create');
    }

    public function update(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.update')
            && $this->view($user, $transfer)
            && in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Submitted], true);
    }

    public function submit(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.submit')
            && $this->view($user, $transfer)
            && $transfer->status === TransferStatus::Draft;
    }

    public function confirmCurrentOrganization(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.confirmCurrentOrganization')
            && $this->organizationScopeService->canAccessOrganization($user, $transfer->from_organization_id)
            && $transfer->status === TransferStatus::Submitted;
    }

    public function confirmReceivingOrganization(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.confirmReceivingOrganization')
            && $this->organizationScopeService->canAccessOrganization($user, $transfer->to_organization_id)
            && in_array($transfer->status, [TransferStatus::Submitted, TransferStatus::CurrentOrganizationConfirmed], true);
    }

    public function approve(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.approve')
            && $this->view($user, $transfer)
            && in_array($transfer->status, [TransferStatus::CurrentOrganizationConfirmed, TransferStatus::ReceivingOrganizationConfirmed], true);
    }

    public function reject(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.reject')
            && $this->view($user, $transfer)
            && ! $transfer->status->isFinal()
            && $transfer->status !== TransferStatus::Approved;
    }

    public function cancel(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.cancel')
            && $this->view($user, $transfer)
            && ! $transfer->status->isFinal()
            && $transfer->status !== TransferStatus::Approved;
    }

    public function complete(User $user, EmployeeTransfer $transfer): bool
    {
        return $user->can('transfers.complete')
            && $this->view($user, $transfer)
            && $transfer->status === TransferStatus::Approved;
    }
}
