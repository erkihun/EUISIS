<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TransferAnnouncementStatus;
use App\Models\TransferAnnouncement;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class TransferAnnouncementPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('transfers.announcements.view');
    }

    public function view(User $user, TransferAnnouncement $announcement): bool
    {
        return $user->can('transfers.announcements.view')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('transfers.announcements.create');
    }

    /** Only Draft announcements may be edited/updated. */
    public function update(User $user, TransferAnnouncement $announcement): bool
    {
        return $user->can('transfers.announcements.update')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id)
            && $announcement->status === TransferAnnouncementStatus::Draft;
    }

    public function publish(User $user, TransferAnnouncement $announcement): bool
    {
        return $user->can('transfers.announcements.publish')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id);
    }

    /**
     * Permission gate for close route.
     * Status validation is handled inside CloseTransferAnnouncementAction (throws DomainException).
     * The controller catches that and redirects with an error flash — not a 403.
     */
    public function close(User $user, TransferAnnouncement $announcement): bool
    {
        return $user->can('transfers.announcements.close')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id);
    }

    /**
     * Cancel shares the 'close' permission in the test suite.
     * Status finality is enforced inside CancelTransferAnnouncementAction.
     */
    public function cancel(User $user, TransferAnnouncement $announcement): bool
    {
        // Cancelling a draft is part of closing announcements.
        return $user->can('transfers.announcements.close')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id);
    }

    /** Deletion uses the 'update' permission (drafts only). */
    public function delete(User $user, TransferAnnouncement $announcement): bool
    {
        return $user->can('transfers.announcements.update')
            && $this->organizationScopeService->canAccessOrganization($user, $announcement->organization_id)
            && $announcement->status === TransferAnnouncementStatus::Draft;
    }

    public function restore(User $user, TransferAnnouncement $announcement): bool
    {
        // No restore route exists; a removed announcement is re-created instead.
        return false;
    }
}
