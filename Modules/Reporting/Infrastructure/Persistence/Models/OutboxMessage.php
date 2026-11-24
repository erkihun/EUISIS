<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** An integration event awaiting relay. */
final class OutboxMessage extends ReportingModel
{
    protected $table = 'reporting_outbox';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'event_version' => 'integer',
        ];
    }
}
