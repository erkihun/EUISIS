<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceDecisionType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a decision needs executive approval and which position approves it. The approver is resolved at runtime from the position's current holder, so leadership changes need no workflow edits.
 */
class GrievanceApprovalRule extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_approval_rules';

    protected $fillable = [
        'name_en',
        'name_am',
        'organization_id',
        'handler_type',
        'handler_id',
        'category_id',
        'decision_type',
        'requires_approval',
        'approver_position_id',
        'approval_sla_profile_id',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handler_type' => GrievanceHandlerType::class,
            'decision_type' => GrievanceDecisionType::class,
            'requires_approval' => 'bool',
            'is_active' => 'bool',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'priority' => 'int',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function approverPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'approver_position_id');
    }

    public function approvalSlaProfile(): BelongsTo
    {
        return $this->belongsTo(GrievanceSlaProfile::class, 'approval_sla_profile_id');
    }
}
