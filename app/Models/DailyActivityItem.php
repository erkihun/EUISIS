<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyActivityCategory;
use App\Enums\DailyActivityProgressStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of work within a daily log.
 *
 * `position_service_id` links the work to a service registered for the
 * employee's position; null means "other work activity" (meetings, urgent
 * instructions, administrative work). `performance_activity_id` and `kpi_id`
 * are reserved for EPMS and carry no foreign keys yet.
 */
class DailyActivityItem extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'activity_category',
        'position_service_id',
        'title',
        'description',
        'output_result',
        'progress_status',
        'started_at',
        'ended_at',
        'duration_minutes',
        'quantity',
        'unit_of_measure',
        'challenge_issue',
        'next_action',
        'employee_performance_item_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'activity_category' => DailyActivityCategory::class,
            'progress_status' => DailyActivityProgressStatus::class,
            'duration_minutes' => 'integer',
            'quantity' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(DailyActivityLog::class, 'daily_activity_log_id');
    }

    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }
}
