<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseInspectionRequest extends Model
{
    protected $table = 'court_case_inspection_requests';

    protected $fillable = [
        'court_case_id',
        'request_date',
        'subject',
        'request_note',
        'status',
        'requested_by_user_id',
        'assigned_inspector_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'request_date' => 'date',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'court_case_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignedInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_inspector_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(CaseInspectionFinding::class, 'case_inspection_request_id');
    }
}
