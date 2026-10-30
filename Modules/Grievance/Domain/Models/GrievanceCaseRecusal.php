<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceRecusalStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conflict-of-interest declaration by a panel member on one case stage, its decision and any replacement.
 */
class GrievanceCaseRecusal extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_case_recusals';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'committee_id',
        'stage_member_id',
        'employee_id',
        'reason',
        'status',
        'declared_by',
        'declared_at',
        'decided_by',
        'decided_at',
        'decision_notes',
        'replacement_employee_id',
        'replacement_stage_member_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceRecusalStatus::class,
            'declared_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function replacementEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replacement_employee_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
