<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sequential review step of a submitted version. */
final class ReviewStep extends ReportingModel
{
    protected $table = 'reporting_review_steps';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step_no' => 'integer',
            'due_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }
}
