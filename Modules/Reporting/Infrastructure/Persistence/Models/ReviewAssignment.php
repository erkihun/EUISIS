<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** Delegation or reassignment of a review step; history, never edited. */
final class ReviewAssignment extends ReportingModel
{
    protected $table = 'reporting_review_assignments';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
