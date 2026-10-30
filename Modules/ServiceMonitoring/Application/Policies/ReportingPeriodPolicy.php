<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Fiscal years and periods: anyone in the module sees them; the city coordinator manages, locks and reopens. Every check goes through ServiceMonitoringAccess. */
final class ReportingPeriodPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewModule($user);
    }

    public function view(User $user, ReportingPeriod $period): bool
    {
        return $this->access->canViewModule($user);
    }

    public function manage(User $user): bool
    {
        return $this->access->canManagePeriods($user);
    }

    public function lock(User $user, ReportingPeriod $period): bool
    {
        return $this->access->canManagePeriods($user) && ! $period->isLocked();
    }

    public function reopen(User $user, ReportingPeriod $period): bool
    {
        return $this->access->canReopenPeriods($user) && $period->isLocked();
    }
}
