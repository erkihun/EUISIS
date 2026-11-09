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

    /** The columns a standard may hang off; exactly one is set per row. */
    public const SUBJECT_COLUMNS = ['task_id', 'master_task_id', 'activity_id'];

    protected $fillable = [
        'task_id',
        'master_task_id',
        'activity_id',
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
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // One subject per standard, on every driver (PostgreSQL also has a CHECK).
        static::saving(function (self $standard): void {
            $set = array_filter(array_map(fn (string $column) => $standard->getAttribute($column), self::SUBJECT_COLUMNS));
            if (count($set) !== 1) {
                throw new \LogicException('A work standard measures exactly one of: a position task, a master task or an activity.');
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PositionServiceTask::class, 'task_id');
    }

    public function masterTask(): BelongsTo
    {
        return $this->belongsTo(ProcessTask::class, 'master_task_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProcessActivity::class, 'activity_id');
    }

    /** The column that names this standard's subject. */
    public static function subjectColumnFor(PositionServiceTask|ProcessTask|ProcessActivity $subject): string
    {
        return match (true) {
            $subject instanceof ProcessTask => 'master_task_id',
            $subject instanceof ProcessActivity => 'activity_id',
            default => 'task_id',
        };
    }

    public function subjectColumn(): string
    {
        foreach (self::SUBJECT_COLUMNS as $column) {
            if ($this->getAttribute($column) !== null) {
                return $column;
            }
        }

        return 'task_id';
    }

    public function subjectModel(): PositionServiceTask|ProcessTask|ProcessActivity
    {
        return match ($this->subjectColumn()) {
            'master_task_id' => $this->masterTask ?? abort(404),
            'activity_id' => $this->activity ?? abort(404),
            default => $this->task ?? abort(404),
        };
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
