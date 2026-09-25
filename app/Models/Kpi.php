<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\KpiDirection;
use App\Enums\Performance\KpiFrequency;
use App\Enums\Performance\KpiMeasurementType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KPI library entry: how a measure is expressed, its direction and how it aggregates.
 */
class Kpi extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'kpis';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'organization_id',
        'measurement_type',
        'unit_of_measure',
        'direction',
        'aggregation_method',
        'data_source_type',
        'system_source_key',
        'calculation_formula',
        'baseline',
        'frequency',
        'allow_overachievement',
        'achievement_cap',
        'target_tolerance',
        'zero_score_deviation',
        'milestones',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'measurement_type' => KpiMeasurementType::class,
            'direction' => KpiDirection::class,
            'aggregation_method' => KpiAggregation::class,
            'data_source_type' => KpiDataSource::class,
            'frequency' => KpiFrequency::class,
            'baseline' => 'decimal:4',
            'achievement_cap' => 'decimal:4',
            'target_tolerance' => 'decimal:4', 'zero_score_deviation' => 'decimal:4',
            'milestones' => 'array',
            'allow_overachievement' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'kpi_id');
    }
}
