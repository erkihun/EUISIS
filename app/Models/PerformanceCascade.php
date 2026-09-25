<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\CascadeType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recorded parent -> child objective lineage.
 */
class PerformanceCascade extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'performance_cascades';

    protected $fillable = [
        'cycle_id',
        'parent_plan_id',
        'child_plan_id',
        'parent_objective_id',
        'child_objective_id',
        'source_level',
        'target_level',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'cascade_type',
        'contribution_weight',
        'aggregation_rule',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cascade_type' => CascadeType::class,
            'contribution_weight' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function parentObjective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'parent_objective_id');
    }

    public function childObjective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'child_objective_id');
    }

    public function childPlan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'child_plan_id');
    }
}
