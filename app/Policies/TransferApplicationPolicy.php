<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TransferApplicationStatus;
use App\Models\TransferApplication;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class TransferApplicationPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('transfers.applications.view') || $user->can('transfers.viewAny');
    }

    public function view(User $user, TransferApplication $application): bool
    {
        if ($this->isApplicant($user, $application)) {
            return true;
        }

        if (! ($user->can('transfers.applications.view') || $user->can('transfers.viewAny'))) {
            return false;
        }

        return $this->organizationScopeService->canAccessOrganization($user, $application->releasing_organization_id)
            || $this->organizationScopeService->canAccessOrganization($user, $application->receiving_organization_id);
    }

    public function withdraw(User $user, TransferApplication $application): bool
    {
        return $this->isApplicant($user, $application) && ! $application->status->isFinal();
    }

    public function screen(User $user, TransferApplication $application): bool
    {
        return ($user->can('transfers.applications.screen') || $user->can('transfers.update'))
            && $this->view($user, $application)
            && in_array($application->status, [
                TransferApplicationStatus::Submitted,
                TransferApplicationStatus::UnderReview,
            ], true);
    }

    public function select(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.approve')
            && $this->view($user, $application)
            && $application->status === TransferApplicationStatus::Verified;
    }

    public function reject(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.reject')
            && $this->view($user, $application)
            && ! $application->status->isFinal();
    }

    public function approveRelease(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.release.approve')
            && $this->organizationScopeService->canAccessOrganization($user, $application->releasing_organization_id)
            && $application->status === TransferApplicationStatus::ReleasePending;
    }

    public function approveReceiving(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.receiving.approve')
            && $this->organizationScopeService->canAccessOrganization($user, $application->receiving_organization_id)
            && $application->status === TransferApplicationStatus::ReceivingPending;
    }

    public function approveFinal(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.final.approve')
            && $this->view($user, $application)
            && $application->status === TransferApplicationStatus::FinalApprovalPending;
    }

    public function complete(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.complete')
            && $this->view($user, $application)
            && $application->status === TransferApplicationStatus::Approved;
    }

    private function isApplicant(User $user, TransferApplication $application): bool
    {
        $employee = $user->employee;

        return $employee !== null && $employee->id === $application->employee_id;
    }
}
