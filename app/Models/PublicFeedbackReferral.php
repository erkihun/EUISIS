<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controlled request to take a reviewed public complaint into the
 * discipline domain (brief §59–60). It records who asked, who decided and
 * what they said; `disciplinary_case_id` is filled only by the discipline
 * module when it opens a case from an accepted request. The complaint row
 * is never converted.
 */
class PublicFeedbackReferral extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_DISCIPLINE = 'discipline';

    public const STATUSES = ['requested', 'accepted', 'declined', 'withdrawn'];

    protected $fillable = ['submission_id', 'kind', 'status', 'notes', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_notes', 'disciplinary_case_id'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'requested';
    }
}
