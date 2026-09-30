<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceMinutesStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meeting minutes. Draft minutes are editable; confirmed minutes change only through a new version that supersedes them.
 */
class GrievanceMinutes extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_minutes';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'hearing_id',
        'meeting_date',
        'summary',
        'discussion',
        'resolutions',
        'attendees',
        'status',
        'version_no',
        'supersedes_minutes_id',
        'amendment_reason',
        'prepared_by',
        'prepared_by_employee_id',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceMinutesStatus::class,
            'meeting_date' => 'date',
            'attendees' => 'array',
            'confirmed_at' => 'datetime',
            'version_no' => 'int',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(GrievanceHearing::class, 'hearing_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
