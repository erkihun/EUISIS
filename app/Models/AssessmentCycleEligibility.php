<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentCycleEligibility extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_cycle_employee_eligibility';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AssessmentCycle::class, 'assessment_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(AssessmentUnassessedReason::class, 'reason_id');
    }
}
