<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;

/** The module settings. Every check goes through ServiceMonitoringAccess. */
final class MonitoringSettingPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function manage(User $user): bool
    {
        return $this->access->canManageSettings($user);
    }
}
