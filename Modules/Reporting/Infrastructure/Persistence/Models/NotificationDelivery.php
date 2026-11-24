<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A notice requested for one person; the unique key prevents a duplicate. */
final class NotificationDelivery extends ReportingModel
{
    protected $table = 'reporting_notification_deliveries';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
