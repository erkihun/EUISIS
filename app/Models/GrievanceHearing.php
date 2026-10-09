<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceHearingMode;
use App\Enums\Grievance\GrievanceHearingStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A formal hearing/meeting of a case stage.
 */
class GrievanceHearing extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_hearings';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'scheduled_at',
        'duration_minutes',
        'location',
        'mode',
        'meeting_link',
        'status',
        'chairperson_employee_id',
        'agenda',
        'notes',
        'cancellation_reason',
        'held_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => GrievanceHearingMode::class,
            'status' => GrievanceHearingStatus::class,
            'scheduled_at' => 'datetime',
            'held_at' => 'datetime',
            'meeting_link' => 'encrypted',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'chairperson_employee_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GrievanceHearingParticipant::class, 'hearing_id');
    }

    public function minutes(): HasMany
    {
        return $this->hasMany(GrievanceMinutes::class, 'hearing_id');
    }
}
