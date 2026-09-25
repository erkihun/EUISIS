<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class IdCardPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('id-cards.viewAny') || $user->can('cards.view');
    }

    public function view(User $user, IdCard $idCard): bool
    {
        return ($user->can('id-cards.view') || $user->can('cards.view'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function create(User $user): bool
    {
        return $user->can('id-cards.create') || $user->can('cards.manage');
    }

    public function update(User $user, IdCard $idCard): bool
    {
        return ($user->can('id-cards.update') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function archive(User $user, IdCard $idCard): bool
    {
        return $user->can('id-cards.archive')
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function print(User $user, IdCard $idCard): bool
    {
        return $idCard->status === CardStatus::PendingPrint
            && ($user->can('id-cards.print') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function reprint(User $user, IdCard $idCard): bool
    {
        return in_array($idCard->status, [CardStatus::Printed, CardStatus::Issued, CardStatus::Active], true)
            && $idCard->is_current
            && ($user->can('id-cards.print') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function issue(User $user, IdCard $idCard): bool
    {
        return $idCard->status === CardStatus::Printed
            && ($user->can('id-cards.issue') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function activate(User $user, IdCard $idCard): bool
    {
        return $idCard->status === CardStatus::Issued
            && ($user->can('id-cards.activate') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function reportLost(User $user, IdCard $idCard): bool
    {
        return in_array($idCard->status, [CardStatus::Active, CardStatus::Issued], true)
            && ($user->can('id-cards.reportLost') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function reportDamaged(User $user, IdCard $idCard): bool
    {
        return in_array($idCard->status, [CardStatus::Active, CardStatus::Issued], true)
            && ($user->can('id-cards.reportDamaged') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function replace(User $user, IdCard $idCard): bool
    {
        return in_array($idCard->status, [CardStatus::Lost, CardStatus::Damaged, CardStatus::Expired, CardStatus::Active], true)
            && ($user->can('id-cards.replace') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function revoke(User $user, IdCard $idCard): bool
    {
        return ! in_array($idCard->status, [CardStatus::Revoked, CardStatus::Replaced, CardStatus::Expired], true)
            && ($user->can('id-cards.revoke') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $idCard->employee);
    }

    public function exportPng(User $user, IdCard $idCard): bool
    {
        $employee = $idCard->employee;

        return $employee !== null
            && $user->can('id-cards.exportPng')
            && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }

    public function previewSvg(User $user, IdCard $idCard): bool
    {
        $employee = $idCard->employee;

        return $employee !== null
            && ($user->can('id-cards.previewSvg') || $user->can('id-cards.exportPng') || $user->can('cards.manage'))
            && $this->organizationScopeService->canAccessEmployee($user, $employee);
    }
}
