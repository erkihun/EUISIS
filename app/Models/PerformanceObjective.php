<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\CascadeMode;
use App\Enums\Performance\ObjectiveType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An objective inside one plan version. Lineage: parent_objective_id (cascade), source_objective_id (previous version).
 */
class PerformanceObjective extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_objectives';

    protected $attributes = ['status' => 'ACTIVE', 'weight' => '0', 'is_mandatory' => false];

    protected $fillable = [
        'performance_plan_id',
        'strategic_goal_id',
        'parent_objective_id',
        'source_objective_id',
        'code',
        'title_en',
        'title_am',
        'description_en',
        'description_am',
        'objective_type',
        'cascade_mode',
        'is_mandatory',
        'weight',
        'absolute_weight_percent',
        'local_weight_percent',
        'priority',
        'owner_type',
        'owner_id',
        'start_date',
        'end_date',
        'rejection_reason',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'objective_type' => ObjectiveType::class,
            'cascade_mode' => CascadeMode::class,
            'is_mandatory' => 'boolean',
            'weight' => 'decimal:4',
            'absolute_weight_percent' => 'decimal:4',
            'local_weight_percent' => 'decimal:4',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'performance_plan_id');
    }

    public function strategicGoal(): BelongsTo
    {
        return $this->belongsTo(StrategicGoal::class, 'strategic_goal_id');
    }

    public function parentObjective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'parent_objective_id');
    }

    public function childObjectives(): HasMany
    {
        return $this->hasMany(PerformanceObjective::class, 'parent_objective_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'objective_id')->where('is_current', true);
    }
}
