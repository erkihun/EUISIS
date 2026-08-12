<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VacancyApplication;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class VacancyApplicationPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('vacancy-applications.viewAny');
    }

    public function view(User $user, VacancyApplication $application): bool
    {
        if ($this->isApplicant($user, $application)) {
            return true;
        }

        if (! $user->can('vacancy-applications.view')) {
            return false;
        }

        return $this->organizationScopeService->canAccessOrganization(
            $user,
            $application->positionEntry?->organization_id,
        );
    }

    public function submit(User $user): bool
    {
        return $user->can('vacancy-applications.submit');
    }

    public function withdraw(User $user, VacancyApplication $application): bool
    {
        return $this->isApplicant($user, $application) && ! $application->status->isFinal();
    }

    public function screen(User $user, VacancyApplication $application): bool
    {
        return $user->can('vacancy-applications.screen') && $this->view($user, $application);
    }

    public function select(User $user, VacancyApplication $application): bool
    {
        return $user->can('vacancy-applications.select') && $this->view($user, $application);
    }

    public function reject(User $user, VacancyApplication $application): bool
    {
        return $user->can('vacancy-applications.reject') && $this->view($user, $application);
    }

    public function initiateTransfer(User $user, VacancyApplication $application): bool
    {
        return $user->can('vacancy-applications.initiateTransfer') && $this->view($user, $application);
    }

    private function isApplicant(User $user, VacancyApplication $application): bool
    {
        $employee = $user->employee;

        return $employee !== null && $employee->id === $application->employee_id;
    }
}
