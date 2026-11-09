<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;

/** Innovation & Technology Bureau verification of reported digital services (D20). Every check goes through ServiceMonitoringAccess. */
final class DigitalServicePolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function verify(User $user, DigitalService $service): bool
    {
        return $this->access->canVerifyTechnology($user);
    }
}
