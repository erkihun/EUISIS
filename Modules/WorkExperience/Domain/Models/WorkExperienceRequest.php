<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;

/**
 * An employee's request for a written work experience certificate. It is a
 * form, not a letter. Once accepted, `certificate` holds the certificate
 * exactly as it will be printed, so a later change to the record never
 * changes a paper that was already signed.
 */
class WorkExperienceRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'work_experience_requests';

    protected $fillable = [
        'reference_number', 'employee_id', 'organization_id', 'requester_user_id', 'registered_by_office',
        'addressee', 'purpose', 'language', 'status', 'submitted_at', 'decided_by', 'decided_at', 'decision_comment',
        'certificate_year', 'certificate_sequence', 'certificate_number', 'verification_code', 'certificate',
        'print_count', 'printed_at', 'sent_by', 'sent_at', 'delivery_note',
    ];

    protected function casts(): array
    {
        return [
            'registered_by_office' => 'bool',
            'status' => WorkExperienceStatus::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'printed_at' => 'datetime',
            'sent_at' => 'datetime',
            'certificate' => 'array',
            'certificate_year' => 'int',
            'certificate_sequence' => 'int',
            'print_count' => 'int',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
