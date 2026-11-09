<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** The participant's report is due today (D23). */
final class DueTodayNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'due_today';
    }
}
