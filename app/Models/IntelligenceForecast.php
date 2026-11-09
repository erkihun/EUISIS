<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One predicted point of one forecast snapshot (brief §69–70): never
 * overwritten; when the target month has passed, the actual and the error
 * are written beside it so forecast quality can be measured over time.
 */
class IntelligenceForecast extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'metric', 'scope_key', 'period', 'value', 'lower', 'upper', 'method', 'model_version', 'confidence', 'points', 'r2',
        'assumptions', 'limitations', 'data_through', 'generated_at', 'actual', 'abs_error', 'pct_error', 'evaluated_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'lower' => 'float',
            'upper' => 'float',
            'r2' => 'float',
            'actual' => 'float',
            'abs_error' => 'float',
            'pct_error' => 'float',
            'points' => 'integer',
            'assumptions' => 'array',
            'limitations' => 'array',
            'generated_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
