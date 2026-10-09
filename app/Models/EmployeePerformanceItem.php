<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\KpiFrequency;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weighted KPI in an agreement. May adapt the position target; lineage kept in position_target_id.
 */
class EmployeePerformanceItem extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_performance_items';

    protected $attributes = ['is_current' => true];

    protected $fillable = [
        'agreement_id',
        'objective_id',
        'kpi_id',
        'position_target_id',
        'expected_output',
        'weight',
        'baseline_value',
        'target_value',
        'target_numerator',
        'target_denominator',
        'achievement_cap',
        'tolerance',
        'zero_score_deviation',
        'period_type',
        'data_source_type',
        'is_mandatory',
        'is_additional',
        'effective_from',
        'amendment_reason',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'baseline_value' => 'decimal:4',
            'target_value' => 'decimal:4',
            'target_numerator' => 'decimal:4',
            'target_denominator' => 'decimal:4',
            'achievement_cap' => 'decimal:4',
            'tolerance' => 'decimal:4', 'zero_score_deviation' => 'decimal:4',
            'period_type' => KpiFrequency::class,
            'data_source_type' => KpiDataSource::class,
            'is_mandatory' => 'boolean',
            'is_additional' => 'boolean',
            'is_current' => 'boolean',
            'effective_from' => DateOnly::class,
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'objective_id');
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function positionTarget(): BelongsTo
    {
        return $this->belongsTo(KpiTarget::class, 'position_target_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceItem::class, 'supersedes_item_id');
    }

    public function actuals(): HasMany
    {
        return $this->hasMany(KpiActual::class, 'employee_performance_item_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PerformanceEvidence::class, 'employee_performance_item_id');
    }
}
