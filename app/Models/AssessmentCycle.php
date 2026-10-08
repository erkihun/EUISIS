<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentCycle extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'reference_date' => 'date:Y-m-d',
            'submission_deadline' => 'date:Y-m-d', 'verification_deadline' => 'date:Y-m-d',
            'eligibility_snapshot_at' => 'datetime', 'eligibility_finalized_at' => 'datetime',
            'eligible_employee_statuses' => 'array', 'exclusion_reduces_denominator' => 'bool',
            'min_service_days' => 'int', 'small_group_threshold' => 'int', 'reminder_days_before' => 'int',
            'evaluation_due_date' => 'date:Y-m-d', 'assignments_generated_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class, 'assessment_type_id');
    }

    public function bandPolicy(): BelongsTo
    {
        return $this->belongsTo(AssessmentResultBandPolicy::class, 'result_band_policy_id');
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(AssessmentCycleOrganization::class);
    }

    public function eligibility(): HasMany
    {
        return $this->hasMany(AssessmentCycleEligibility::class);
    }

    public function isEligibilityFinalized(): bool
    {
        return $this->eligibility_status === 'finalized';
    }

    /** Employee statuses counted as eligible; ACTIVE until an approved rule says otherwise. */
    public function eligibleStatuses(): array
    {
        return $this->eligible_employee_statuses ?: ['active'];
    }
}
