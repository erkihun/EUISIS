<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;

/** Monitoring visits: the support officers manage them; the institution in scope sees them. Every check goes through ServiceMonitoringAccess. */
final class MonitoringVisitPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function view(User $user, MonitoringVisit $visit): bool
    {
        return $this->access->canViewVisit($user, $visit);
    }

    public function update(User $user, MonitoringVisit $visit): bool
    {
        return $visit->participant !== null && $this->access->canManageVisits($user, $visit->participant);
    }
}
