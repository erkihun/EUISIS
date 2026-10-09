<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskQualitySource;
use App\Enums\TaskStandardStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Task standard / BPR plan (ስታንዳርድ መለኪያ / የBPR ዕቅድ), one row per version.
 *
 * A dimension (quantity, time, quality) applies only when its planned value
 * is set. Approved versions are immutable; see TaskStandardService.
 */
class PositionServiceTaskStandard extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'task_id',
        'organization_id',
        'version_no',
        'status',
        'standard_measure',
        'bpr_reference',
        'planned_quantity',
        'quantity_unit',
        'planned_time_minutes',
        'planned_quality',
        'quality_unit',
        'quality_measure',
        'quality_source',
        'effective_from',
        'effective_to',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => TaskStandardStatus::class,
            'quality_source' => TaskQualitySource::class,
            'planned_quantity' => 'decimal:4',
            'planned_time_minutes' => 'decimal:4',
            'planned_quality' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PositionServiceTask::class, 'task_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Approved and in force on a Gregorian ISO date. */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->where('status', TaskStandardStatus::Approved->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $open) => $open->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** @return array<int, string> the dimensions this standard measures */
    public function dimensions(): array
    {
        return array_keys(array_filter([
            'quantity' => $this->planned_quantity !== null,
            'time' => $this->planned_time_minutes !== null,
            'quality' => $this->planned_quality !== null,
        ]));
    }
}
