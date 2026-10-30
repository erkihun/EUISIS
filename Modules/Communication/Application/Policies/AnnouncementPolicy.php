<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Policies;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Modules\Communication\Application\Services\AnnouncementAudience;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Domain\Models\Announcement;

/**
 * Announcements: readers see live announcements addressed to them; authors and
 * managers act inside their organization scope (a city-wide announcement needs
 * unrestricted scope).
 */
class AnnouncementPolicy
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly AnnouncementAudience $audience,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canUse($user) && $user->can('announcements.view');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        if ($this->manage($user, $announcement)) {
            return true;
        }

        return $this->viewAny($user) && $announcement->isLive() && $this->audience->reaches($user, $announcement);
    }

    /** Authors and scoped managers see drafts, schedules and reports. */
    public function manage(User $user, Announcement $announcement): bool
    {
        return $this->access->canUse($user) && ($announcement->created_by === $user->getKey()
            || $this->scope->canExercisePermission($user, 'announcements.edit', $announcement->organization_id)
            || $this->scope->canExercisePermission($user, 'announcements.publish', $announcement->organization_id));
    }

    public function create(User $user, ?string $organizationId = null): bool
    {
        return $this->access->canUse($user) && $this->scope->canExercisePermission($user, 'announcements.create', $organizationId);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $announcement->status->isEditable() && $this->access->canUse($user)
            && $this->scope->canExercisePermission($user, 'announcements.edit', $announcement->organization_id);
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $announcement->status->isEditable() && $this->access->canUse($user)
            && $this->scope->canExercisePermission($user, 'announcements.publish', $announcement->organization_id);
    }

    public function cancel(User $user, Announcement $announcement): bool
    {
        return in_array($announcement->status->value, ['SCHEDULED', 'PUBLISHED'], true) && $this->access->canUse($user)
            && $this->scope->canExercisePermission($user, 'announcements.cancel', $announcement->organization_id);
    }

    public function exportAcknowledgements(User $user, Announcement $announcement): bool
    {
        return $this->access->canUse($user)
            && $this->scope->canExercisePermission($user, 'announcements.export_acknowledgements', $announcement->organization_id);
    }

    public function acknowledge(User $user, Announcement $announcement): bool
    {
        return $announcement->acknowledgement_required && $this->view($user, $announcement) && $this->audience->reaches($user, $announcement);
    }
}
