<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferApplication;

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
        return $this->isApplicant($user, $application) && in_array($application->status, [
            TransferApplicationStatus::Submitted,
            TransferApplicationStatus::UnderReview,
            TransferApplicationStatus::Verified,
        ], true);
    }

    public function screen(User $user, TransferApplication $application): bool
    {
        // Only a submitted application moves to review (ScreenTransferApplicationAction).
        return ($user->can('transfers.applications.screen') || $user->can('transfers.update'))
            && $this->staffCanAccess($user, $application)
            && $application->status === TransferApplicationStatus::Submitted;
    }

    public function select(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.approve')
            && $this->staffCanAccess($user, $application)
            && in_array($application->status, [
                TransferApplicationStatus::UnderReview,
                TransferApplicationStatus::Verified,
            ], true);
    }

    public function reject(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.reject')
            && $this->staffCanAccess($user, $application)
            && ! $application->status->isFinal();
    }

    public function approveRelease(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.release.approve')
            && ! $this->isApplicant($user, $application)
            && $this->organizationScopeService->canAccessOrganization($user, $application->releasing_organization_id)
            && $application->status === TransferApplicationStatus::ReleasePending;
    }

    public function approveReceiving(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.receiving.approve')
            && ! $this->isApplicant($user, $application)
            && $this->organizationScopeService->canAccessOrganization($user, $application->receiving_organization_id)
            && $application->status === TransferApplicationStatus::ReceivingPending;
    }

    public function approveFinal(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.final.approve')
            && $this->staffCanAccess($user, $application)
            && $application->status === TransferApplicationStatus::FinalApprovalPending;
    }

    public function complete(User $user, TransferApplication $application): bool
    {
        return $user->can('transfers.complete')
            && $this->staffCanAccess($user, $application)
            && in_array($application->status, [
                TransferApplicationStatus::Approved,
                TransferApplicationStatus::ImplementationFailed,
            ], true);
    }

    /**
     * Staff access to an application for a workflow step: never the applicant
     * (no one screens, selects or approves their own transfer), and only within
     * the staff member's organization scope. view() keeps the applicant shortcut
     * for reading; it must not lift scope for a decision.
     */
    private function staffCanAccess(User $user, TransferApplication $application): bool
    {
        if ($this->isApplicant($user, $application)) {
            return false;
        }

        if (! ($user->can('transfers.applications.view') || $user->can('transfers.viewAny'))) {
            return false;
        }

        return $this->organizationScopeService->canAccessOrganization($user, $application->releasing_organization_id)
            || $this->organizationScopeService->canAccessOrganization($user, $application->receiving_organization_id);
    }

    private function isApplicant(User $user, TransferApplication $application): bool
    {
        $employee = $user->employee;

        return $employee !== null && $employee->id === $application->employee_id;
    }
}
