<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceApprovalAction;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a decision's review/approval history.
 */
class GrievanceDecisionApproval extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_decision_approvals';

    protected $fillable = [
        'decision_id',
        'action',
        'actor_user_id',
        'actor_employee_id',
        'actor_position_id',
        'delegation_id',
        'comment',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => GrievanceApprovalAction::class,
            'acted_at' => 'datetime',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'decision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }

    public function actorPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'actor_position_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(GrievanceDelegation::class, 'delegation_id');
    }
}
