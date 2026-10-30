<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's assignment to an organization, unit or employee, published for
 * Assessment's evaluator resolution. Writes are refused.
 */
final class DailyActivityReviewerAssignmentRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'daily_activity_reviewer_assignments';

    protected function casts(): array
    {
        return [
            'include_sub_units' => 'bool',
            'is_active' => 'bool',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /** The name stored in polymorphic columns, as for the Daily work module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\DailyActivityReviewerAssignment';
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
