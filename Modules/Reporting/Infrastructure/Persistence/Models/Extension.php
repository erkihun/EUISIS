<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A request to move a deadline, and its decision. A request alone changes nothing. */
final class Extension extends ReportingModel
{
    protected $table = 'reporting_extensions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_due_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'previous_due_at' => 'immutable_datetime',
            'approved_due_at' => 'immutable_datetime',
            'is_retrospective' => 'boolean',
        ];
    }
}
