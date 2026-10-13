<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pre-grievance initial-resolution record (Regulation 201/2018 Art. 33(1)):
 * the written issue the employee raised with the immediate supervisor /
 * responsible officer, and the outcome of the discussion. Identity and
 * placement are snapshotted so a later transfer cannot rewrite the origin.
 */
class GrievanceInitialIssue extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'outcome' => GrievanceInitialResolutionOutcome::class,
        'status' => GrievanceInitialResolutionStatus::class,
        'written_issue_raised_at' => 'datetime',
        'discussion_date' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_officer_employee_id');
    }
}
