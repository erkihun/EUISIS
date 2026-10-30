<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceMonitoringPeriod;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class ServiceMonitoringPeriodPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('service_monitoring.periods.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('service_monitoring.periods.manage');
    }

    public function update(User $user, ServiceMonitoringPeriod $period): bool
    {
        return $user->can('service_monitoring.periods.manage');
    }
}
