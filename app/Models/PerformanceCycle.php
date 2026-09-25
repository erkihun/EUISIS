<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\CycleStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A performance period. Status changes only through PerformanceCycleService.
 */
class PerformanceCycle extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_cycles';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'organization_id',
        'start_date',
        'end_date',
        'planning_start_date',
        'planning_end_date',
        'midyear_review_start_date',
        'midyear_review_end_date',
        'yearend_review_start_date',
        'yearend_review_end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'planning_start_date' => DateOnly::class,
            'planning_end_date' => DateOnly::class,
            'midyear_review_start_date' => DateOnly::class,
            'midyear_review_end_date' => DateOnly::class,
            'yearend_review_start_date' => DateOnly::class,
            'yearend_review_end_date' => DateOnly::class,
            'is_current' => 'boolean',
            'status' => CycleStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(PerformancePlan::class, 'cycle_id');
    }

    public function strategicGoals(): HasMany
    {
        return $this->hasMany(StrategicGoal::class, 'cycle_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(EmployeePerformanceAgreement::class, 'cycle_id');
    }
}
