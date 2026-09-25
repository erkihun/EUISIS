<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\ResultStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee result. Once FINALIZED the row is immutable; a later change (appeal) creates a new revision.
 */
class PerformanceResult extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_results';

    protected $attributes = ['is_current' => true, 'revision_no' => 1];

    protected $fillable = [
        'employee_id',
        'cycle_id',
        'agreement_id',
        'organization_id',
        'organization_unit_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResultStatus::class,
            'is_current' => 'boolean',
            'results_score' => 'decimal:4',
            'competency_score' => 'decimal:4',
            'results_weight' => 'decimal:4',
            'competency_weight' => 'decimal:4',
            'calculated_score' => 'decimal:4',
            'adjusted_score' => 'decimal:4',
            'calibrated_score' => 'decimal:4',
            'final_score' => 'decimal:4',
            'snapshot_json' => 'array',
            'calculated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * A finalized result is immutable. Only its release, and being superseded
     * by an appeal revision (is_current), may still change.
     */
    protected static function booted(): void
    {
        static::updating(function (self $result): void {
            $wasFinal = in_array($result->getOriginal('status'), [ResultStatus::Finalized, ResultStatus::PendingRelease, ResultStatus::Released], true);
            if (! $wasFinal) {
                return;
            }

            $changed = array_keys($result->getDirty());
            $allowed = ['status', 'released_at', 'released_by', 'is_current', 'updated_at'];
            if (array_diff($changed, $allowed) !== [] || ($result->isDirty('status') && $result->status !== ResultStatus::Released)) {
                throw new \LogicException('A finalized performance result cannot be changed; create a new revision.');
            }
        });

        static::deleting(function (self $result): void {
            if (in_array($result->status, [ResultStatus::Finalized, ResultStatus::PendingRelease, ResultStatus::Released], true)) {
                throw new \LogicException('A finalized performance result cannot be deleted.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PerformanceScoreAdjustment::class, 'result_id');
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(PerformanceRatingBand::class, 'rating_band_id');
    }
}
