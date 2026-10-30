<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\EmployeePerformanceItem;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity in a daily log, published for Performance (evidence, KPI
 * actuals, cascade traces) and the work-structure pages. Writes are refused.
 */
final class DailyActivityItemRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'daily_activity_items';

    protected function casts(): array
    {
        return [
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

    /** The name stored in polymorphic columns, as for the Daily work module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\DailyActivityItem';
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(DailyActivityLogRecord::class, 'daily_activity_log_id');
    }

    public function performanceItem(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceItem::class, 'employee_performance_item_id');
    }
}
