<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Before/after record of one calibrated result.
 */
class PerformanceCalibrationItem extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_calibration_items';

    protected $fillable = [
        'session_id',
        'result_id',
        'employee_id',
        'manager_score',
        'proposed_score',
        'calibrated_score',
        'reason',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'manager_score' => 'decimal:4',
            'proposed_score' => 'decimal:4',
            'calibrated_score' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PerformanceCalibrationSession::class, 'session_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(PerformanceResult::class, 'result_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
