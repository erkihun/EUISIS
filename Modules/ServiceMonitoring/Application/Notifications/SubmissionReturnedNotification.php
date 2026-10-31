<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** A report was returned for correction; sent at once on return (D23). */
final class SubmissionReturnedNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'returned';
    }
}
