<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached plan score for dashboards. Not a source of truth: recomputed from actuals.
 */
class PerformancePlanScore extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_plan_scores';

    protected $fillable = [
        'performance_plan_id',
        'cycle_id',
        'organization_id',
        'organization_unit_id',
        'as_of',
        'score',
        'trace_json',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => DateOnly::class,
            'score' => 'decimal:4',
            'trace_json' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'performance_plan_id');
    }
}
