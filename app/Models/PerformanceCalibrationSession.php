<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\CalibrationStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calibration panel session. Members come from a reused grievance committee of type performance_calibration.
 */
class PerformanceCalibrationSession extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_calibration_sessions';

    protected $attributes = ['status' => 'DRAFT'];

    protected $fillable = [
        'cycle_id',
        'organization_id',
        'organization_unit_id',
        'committee_id',
        'title',
        'session_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'status' => CalibrationStatus::class,
            'finalized_at' => 'datetime',
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

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PerformanceCalibrationItem::class, 'session_id');
    }
}
