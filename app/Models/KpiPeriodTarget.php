<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiPeriodTarget extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['kpi_target_id', 'period_type', 'period_number', 'target_value', 'target_numerator', 'target_denominator', 'is_cumulative'];

    protected function casts(): array
    {
        return ['target_value' => 'decimal:4', 'target_numerator' => 'decimal:4', 'target_denominator' => 'decimal:4', 'is_cumulative' => 'boolean'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(KpiTarget::class, 'kpi_target_id');
    }
}
