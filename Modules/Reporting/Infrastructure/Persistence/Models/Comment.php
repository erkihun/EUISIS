<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A comment on a report: feedback the reporting side reads, or an internal reviewer note. */
final class Comment extends ReportingModel
{
    protected $table = 'reporting_comments';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
