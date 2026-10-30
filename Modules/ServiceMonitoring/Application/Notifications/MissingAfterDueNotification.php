<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** The participant's report is missing after the deadline; sent to its submitters and its reviewer (D23). */
final class MissingAfterDueNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'missing_after_due';
    }
}
