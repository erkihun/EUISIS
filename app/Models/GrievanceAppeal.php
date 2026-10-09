<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceAppealStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee appeal against an issued decision (distinct from a timeout escalation). One appeal per decision.
 */
class GrievanceAppeal extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_appeals';

    protected $fillable = [
        'grievance_id',
        'appealed_decision_id',
        'from_stage_id',
        'to_stage_id',
        'reason',
        'status',
        'filed_by',
        'filed_by_employee_id',
        'filed_at',
        'deadline_at',
        'routed_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceAppealStatus::class,
            'filed_at' => 'datetime',
            'deadline_at' => 'datetime',
            'routed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'appealed_decision_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'to_stage_id');
    }
}
