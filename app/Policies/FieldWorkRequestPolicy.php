<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Services\FieldWork\FieldWorkSupervisorResolver;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class FieldWorkRequestPolicy
{
    public function __construct(private FieldWorkSupervisorResolver $supervisors, private OrganizationScopeService $scope) {}

    public function view(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id === $request->requester_employee_id
            || ($user->can('field_work.view_team') && $this->supervisors->canApprove($user, $request->requester, $request->assignment))
            || ($user->can('field_work.view_scoped') && $this->scope->canAccessOrganization($user, $request->organization_id));
    }

    public function update(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id === $request->requester_employee_id && $request->status->isEditable() && $user->can('field_work.update_own');
    }

    public function submit(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id === $request->requester_employee_id && $request->status->isEditable() && $user->can('field_work.submit');
    }

    public function approve(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id !== $request->requester_employee_id && $user->can('field_work.approve') && $this->supervisors->canApprove($user, $request->requester, $request->assignment);
    }

    public function returnForCorrection(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id !== $request->requester_employee_id && $user->can('field_work.return') && $this->supervisors->canApprove($user, $request->requester, $request->assignment);
    }

    public function reject(User $user, FieldWorkRequest $request): bool
    {
        return $user->employee?->id !== $request->requester_employee_id && $user->can('field_work.reject') && $this->supervisors->canApprove($user, $request->requester, $request->assignment);
    }

    public function complete(User $user, FieldWorkRequest $request): bool
    {
        return $user->can('field_work.complete')
            && $request->status->isOpen()
            && ($user->employee?->id === $request->requester_employee_id || $this->supervisors->canApprove($user, $request->requester, $request->assignment));
    }
}
