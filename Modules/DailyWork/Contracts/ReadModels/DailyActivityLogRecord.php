<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\DailyWork\Contracts\Data\DailyActivityStatus;

/** An employee's daily log (one per day), published for other modules' reads. Writes are refused. */
final class DailyActivityLogRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'daily_activity_logs';

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'status' => DailyActivityStatus::class,
            'is_late' => 'bool',
            'first_submitted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'submission_count' => 'integer',
        ];
    }

    /** The name stored in polymorphic columns, as for the Daily work module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\DailyActivityLog';
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyActivityItemRecord::class, 'daily_activity_log_id');
    }
}
