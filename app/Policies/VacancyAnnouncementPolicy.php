<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VacancyAnnouncement;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class VacancyAnnouncementPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('vacancy-announcements.viewAny');
    }

    public function view(User $user, VacancyAnnouncement $announcement): bool
    {
        return $user->can('vacancy-announcements.view') && $this->withinScope($user, $announcement);
    }

    public function create(User $user): bool
    {
        return $user->can('vacancy-announcements.create');
    }

    public function update(User $user, VacancyAnnouncement $announcement): bool
    {
        return $user->can('vacancy-announcements.update') && $this->withinScope($user, $announcement);
    }

    public function publish(User $user, VacancyAnnouncement $announcement): bool
    {
        return $user->can('vacancy-announcements.publish') && $this->withinScope($user, $announcement);
    }

    public function close(User $user, VacancyAnnouncement $announcement): bool
    {
        return $user->can('vacancy-announcements.close') && $this->withinScope($user, $announcement);
    }

    public function delete(User $user, VacancyAnnouncement $announcement): bool
    {
        return $user->can('vacancy-announcements.delete') && $this->withinScope($user, $announcement);
    }

    /**
     * An announcement can span multiple positions/organizations. A scoped
     * actor may act on it only if at least one of its positions belongs to
     * an organization inside their accessible scope — mirroring the
     * whereHas() filter already applied on the announcements index query.
     */
    private function withinScope(User $user, VacancyAnnouncement $announcement): bool
    {
        if ($this->organizationScopeService->isUnrestricted($user)) {
            return true;
        }

        $announcement->loadMissing('positions');

        if ($announcement->positions->isEmpty()) {
            return true;
        }

        return $announcement->positions
            ->contains(fn ($position) => $this->organizationScopeService->canAccess($user, $position->organization_id));
    }
}
