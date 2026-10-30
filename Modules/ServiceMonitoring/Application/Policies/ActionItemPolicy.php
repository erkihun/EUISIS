<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;

/** Action items: the visiting team, and the owning institution for its own progress. Every check goes through ServiceMonitoringAccess. */
final class ActionItemPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function update(User $user, ActionItem $item): bool
    {
        return $this->access->canUpdateActionItem($user, $item);
    }
}
