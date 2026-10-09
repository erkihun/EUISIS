<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\KpiDataSource;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A measured actual for one subject (plan target or agreement item), period and source. Unique per (subject, period, source).
 */
class KpiActual extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'kpi_actuals';

    protected $attributes = ['verified' => false];

    protected $fillable = [
        'kpi_id',
        'target_id',
        'employee_performance_item_id',
        'subject_key',
        'performance_plan_id',
        'agreement_id',
        'employee_id',
        'organization_id',
        'organization_unit_id',
        'period_start',
        'period_end',
        'actual_value',
        'actual_numerator',
        'actual_denominator',
        'milestone_key',
        'source_type',
        'source_key',
        'source_reference_type',
        'source_reference_id',
        'comment',
        'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'actual_value' => 'decimal:4',
            'actual_numerator' => 'decimal:4',
            'actual_denominator' => 'decimal:4',
            'source_type' => KpiDataSource::class,
            'verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(KpiTarget::class, 'target_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceItem::class, 'employee_performance_item_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }
}
