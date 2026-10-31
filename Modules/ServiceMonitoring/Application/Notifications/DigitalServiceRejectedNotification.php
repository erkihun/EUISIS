<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** The Innovation & Technology Bureau rejected a reported digital service (D20). */
final class DigitalServiceRejectedNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'digital_service_rejected';
    }
}
