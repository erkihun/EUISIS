<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** A support officer replied to the institution's clarification (D22). */
final class ClarificationRepliedNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'clarification_replied';
    }
}
