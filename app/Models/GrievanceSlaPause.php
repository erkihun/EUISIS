<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceSlaPauseReason;
use App\Enums\Grievance\GrievanceSlaPauseStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved pause of a stage deadline. Records the due date before and after so the recalculation is auditable.
 */
class GrievanceSlaPause extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_sla_pauses';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'pause_reason',
        'status',
        'notes',
        'requested_by',
        'requested_at',
        'approved_by',
        'started_at',
        'ended_at',
        'ended_by',
        'due_at_before',
        'due_at_after',
        'paused_days',
    ];

    protected function casts(): array
    {
        return [
            'pause_reason' => GrievanceSlaPauseReason::class,
            'status' => GrievanceSlaPauseStatus::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'due_at_before' => 'datetime',
            'due_at_after' => 'datetime',
            'paused_days' => 'int',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
