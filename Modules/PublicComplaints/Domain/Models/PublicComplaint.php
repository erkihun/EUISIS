<?php

declare(strict_types=1);

namespace Modules\PublicComplaints\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;

/**
 * A public service complaint (የተገልጋይ ቅሬታ), in the table this module owns
 * (ADR 0032). Not an employee grievance. The shared intake and case handling
 * (Feedback) write the row through the PublicSubmissionType this module
 * registers; this model reads it for the module's own pages and checks.
 */
final class PublicComplaint extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_complaints';

    /** Written through the intake (Feedback), never mass-assigned here. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'complaint_details' => 'encrypted:array',
            'submitted_at' => 'datetime',
        ];
    }
}
