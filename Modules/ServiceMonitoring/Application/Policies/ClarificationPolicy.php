<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\Clarification;

/** Clarification threads: the institution in scope and its support officers. Every check goes through ServiceMonitoringAccess. */
final class ClarificationPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function view(User $user, Clarification $clarification): bool
    {
        return $this->access->canViewClarification($user, $clarification);
    }

    public function reply(User $user, Clarification $clarification): bool
    {
        return $this->access->canViewClarification($user, $clarification);
    }

    public function close(User $user, Clarification $clarification): bool
    {
        return $this->access->canRespond($user, $clarification) || $clarification->opened_by === $user->id;
    }
}
