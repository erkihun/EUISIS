<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\KpiFrequency;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A KPI target inside a plan. Amendments create a new version (amended_from_id) instead of overwriting.
 */
class KpiTarget extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'kpi_targets';

    protected $attributes = ['is_current' => true, 'version_no' => 1];

    protected $fillable = [
        'kpi_id',
        'performance_plan_id',
        'objective_id',
        'parent_target_id',
        'period_type',
        'period_start',
        'period_end',
        'baseline_value',
        'target_value',
        'target_numerator',
        'target_denominator',
        'weight',
        'achievement_cap',
        'tolerance',
        'zero_score_deviation',
        'minimum_acceptable_value',
        'stretch_target',
        'effective_from',
        'amendment_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => KpiFrequency::class,
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'effective_from' => DateOnly::class,
            'is_current' => 'boolean',
            'baseline_value' => 'decimal:4',
            'target_value' => 'decimal:4',
            'target_numerator' => 'decimal:4',
            'target_denominator' => 'decimal:4',
            'weight' => 'decimal:4',
            'achievement_cap' => 'decimal:4',
            'tolerance' => 'decimal:4', 'zero_score_deviation' => 'decimal:4',
            'minimum_acceptable_value' => 'decimal:4',
            'stretch_target' => 'decimal:4',
        ];
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'performance_plan_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'objective_id');
    }

    public function parentTarget(): BelongsTo
    {
        return $this->belongsTo(KpiTarget::class, 'parent_target_id');
    }

    public function childTargets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'parent_target_id')->where('is_current', true);
    }

    public function actuals(): HasMany
    {
        return $this->hasMany(KpiActual::class, 'target_id');
    }

    public function periodTargets(): HasMany
    {
        return $this->hasMany(KpiPeriodTarget::class, 'kpi_target_id')->orderBy('period_type')->orderBy('period_number');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(KpiContribution::class, 'parent_target_id');
    }
}
