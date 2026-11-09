<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** The participant's report is due in three days (D23). */
final class DueInThreeDaysNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'due_in_3_days';
    }
}
