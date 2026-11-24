<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A progress note on a follow-up action. */
final class FollowUpUpdate extends ReportingModel
{
    protected $table = 'reporting_follow_up_updates';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'progress' => 'integer',
        ];
    }
}
