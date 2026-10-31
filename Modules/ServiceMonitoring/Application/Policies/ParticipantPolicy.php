<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** The reporting registry and what can be done for one participant. Every check goes through ServiceMonitoringAccess. */
final class ParticipantPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewModule($user);
    }

    public function view(User $user, Participant $participant): bool
    {
        return $this->access->canViewParticipant($user, $participant);
    }

    public function manage(User $user): bool
    {
        return $this->access->canManageParticipants($user);
    }

    public function grantExtension(User $user, Participant $participant): bool
    {
        return $this->access->canGrantExtension($user, $participant);
    }

    public function consolidate(User $user, Participant $participant): bool
    {
        return $this->access->canConsolidateSubCity($user, $participant);
    }

    public function recordNonSubmission(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.consolidate')
            && ($this->access->isCitywide($user) || ($participant->parent !== null && $this->access->canConsolidateSubCity($user, $participant->parent)));
    }

    public function start(User $user, Participant $participant): bool
    {
        return $user->can('service_monitoring.submit') && $this->access->reaches($user, $participant->organization_id);
    }

    public function openClarification(User $user, Participant $participant): bool
    {
        return $this->access->canOpenClarification($user, $participant);
    }

    public function manageVisits(User $user, Participant $participant): bool
    {
        return $this->access->canManageVisits($user, $participant);
    }
}
