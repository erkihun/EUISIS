<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** A reporting period opened for the participant (D23). */
final class PeriodOpenedNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'period_opened';
    }
}
