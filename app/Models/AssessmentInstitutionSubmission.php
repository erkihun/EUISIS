<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentInstitutionSubmission extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary_snapshot' => 'array', 'coverage_percent' => 'decimal:4', 'submitted_at' => 'datetime', 'returned_at' => 'datetime',
            'review_started_at' => 'datetime', 'verified_at' => 'datetime', 'finalized_at' => 'datetime', 'outdated_at' => 'datetime',
            'last_verification_reminded_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AssessmentCycle::class, 'assessment_cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function cityReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'city_reviewer_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssessmentSubmissionEvent::class, 'submission_id')->orderBy('created_at');
    }
}
