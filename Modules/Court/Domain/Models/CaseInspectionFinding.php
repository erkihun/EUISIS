<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseInspectionFinding extends Model
{
    protected $table = 'court_case_inspection_findings';

    protected $fillable = [
        'case_inspection_request_id',
        'finding_date',
        'title',
        'details',
        'attachment_path',
        'attachment_original_name',
        'severity',
        'accepted_at',
        'accepted_by_user_id',
        'recorded_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'finding_date' => 'date',
        'accepted_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(CaseInspectionRequest::class, 'case_inspection_request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
