<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Notifications;

/** A support action item is past its due date (D22, D23). */
final class ActionItemOverdueNotification extends ServiceMonitoringNotification
{
    public function kind(): string
    {
        return 'action_item_overdue';
    }
}
