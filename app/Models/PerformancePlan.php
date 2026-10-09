<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organization, unit or position plan. Published versions are immutable; changes create a new version (PerformancePlanService::newVersion).
 */
class PerformancePlan extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_plans';

    protected $fillable = [
        'cycle_id',
        'plan_type',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'parent_plan_id',
        'title',
        'effective_from',
        'effective_to',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'plan_type' => PlanType::class,
            'status' => PlanStatus::class,
            'effective_from' => DateOnly::class,
            'effective_to' => DateOnly::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function parentPlan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'parent_plan_id');
    }

    public function childPlans(): HasMany
    {
        return $this->hasMany(PerformancePlan::class, 'parent_plan_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'supersedes_plan_id');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(PerformanceObjective::class, 'performance_plan_id')->orderBy('sort_order');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'performance_plan_id');
    }
}
