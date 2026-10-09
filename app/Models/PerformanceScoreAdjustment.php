<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\AdjustmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit, approved change to a calculated score. Never silent.
 */
class PerformanceScoreAdjustment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_score_adjustments';

    protected $attributes = ['status' => 'PENDING'];

    protected $fillable = [
        'result_id',
        'adjustment_type',
        'original_score',
        'adjusted_score',
        'reason',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'original_score' => 'decimal:4',
            'adjusted_score' => 'decimal:4',
            'status' => AdjustmentStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(PerformanceResult::class, 'result_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
