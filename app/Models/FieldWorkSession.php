<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkSessionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-participant operational interval. GPS evidence remains in location events. */
class FieldWorkSession extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id', 'field_work_request_id', 'field_work_participant_id', 'employee_id', 'employee_assignment_id', 'approved_start_at', 'approved_end_at', 'checked_in_at', 'checked_out_at', 'attendance_from', 'attendance_to', 'status'];

    protected function casts(): array
    {
        return [
            'status' => FieldWorkSessionStatus::class,
            'approved_start_at' => 'datetime',
            'approved_end_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'attendance_from' => 'datetime',
            'attendance_to' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FieldWorkParticipant::class, 'field_work_participant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
