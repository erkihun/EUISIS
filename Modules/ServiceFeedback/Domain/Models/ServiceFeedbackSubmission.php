<?php

declare(strict_types=1);

namespace Modules\ServiceFeedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;

/**
 * One piece of public service feedback (የተገልጋይ አስተያየት): the rating, its
 * kind and comment, in the table this module owns (ADR 0032). The shared
 * intake (Feedback) writes the row through the PublicSubmissionType this
 * module registers; this model reads it for the module's own checks.
 */
final class ServiceFeedbackSubmission extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_service_feedback';

    /** Written through the intake (Feedback), never mass-assigned here. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'rating' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }
}
