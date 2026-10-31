<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;

/** A queued export is downloadable by the person who asked for it, while they may still export. Every check goes through ServiceMonitoringAccess. */
final class MonitoringExportPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function download(User $user, MonitoringExport $export): bool
    {
        return $export->requested_by === $user->id && $this->access->canExport($user) && $export->isReady();
    }
}
