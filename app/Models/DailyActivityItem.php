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
        // Work execution register: the employee picks the task and records
        // actuals. The standard, planned values and scores are set by the
        // server (WorkStructureResolver) and are deliberately not fillable.
        'sub_service_id',
        'task_id',
        'actual_quality',
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
            'planned_quantity' => 'decimal:4',
            'planned_time_minutes' => 'decimal:4',
            'planned_quality' => 'decimal:4',
            'actual_quality' => 'decimal:4',
            'quantity_score' => 'decimal:4',
            'time_score' => 'decimal:4',
            'quality_score' => 'decimal:4',
            'task_score' => 'decimal:4',
            'standard_snapshot' => 'array',
        ];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(DailyActivityLog::class, 'daily_activity_log_id');
    }

    public function performanceItem(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceItem::class, 'employee_performance_item_id');
    }

    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    public function subService(): BelongsTo
    {
        return $this->belongsTo(PositionServiceSubService::class, 'sub_service_id')->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PositionServiceTask::class, 'task_id')->withTrashed();
    }

    public function taskStandard(): BelongsTo
    {
        return $this->belongsTo(PositionServiceTaskStandard::class, 'task_standard_id');
    }

    /** Recorded against a main task and its standard, not as other work. */
    public function isStructured(): bool
    {
        return $this->task_id !== null && $this->task_standard_id !== null;
    }
}
