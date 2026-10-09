<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child actual consumed by an aggregate target. Unique (parent target, source actual): the same output is never counted twice into one aggregate.
 */
class KpiContribution extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'kpi_contributions';

    protected $fillable = [
        'kpi_id',
        'parent_target_id',
        'contributor_type',
        'contributor_id',
        'source_actual_id',
        'contribution_value',
        'numerator',
        'denominator',
        'weight',
        'source_type',
        'source_reference',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'contribution_value' => 'decimal:4',
            'numerator' => 'decimal:4',
            'denominator' => 'decimal:4',
            'weight' => 'decimal:4',
        ];
    }

    public function parentTarget(): BelongsTo
    {
        return $this->belongsTo(KpiTarget::class, 'parent_target_id');
    }

    public function sourceActual(): BelongsTo
    {
        return $this->belongsTo(KpiActual::class, 'source_actual_id');
    }
}
