<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceDecisionType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned grievance decision of one stage. A returned or rejected version
 * is never overwritten: the correction is a new version that supersedes it.
 * Approved ≠ issued: approval, finalization and letter issue are separate steps.
 */
class GrievanceDecision extends Model
{
    use HasUuidPrimaryKey;

    /** Statuses in which the text may still be edited by the preparer. */
    public const EDITABLE = ['draft', 'returned_for_correction'];

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'decision_no',
        'version_no',
        'decision_type',
        'findings',
        'facts_considered',
        'legal_basis',
        'analysis',
        'decision_text',
        'recommendations',
        'status',
        'requires_executive_approval',
        'approval_rule_id',
        'approver_position_id',
        'corrective_action_required',
        'disciplinary_referral_recommended',
        'quorum_met',
        'prepared_by',
        'prepared_by_employee_id',
        'reviewed_by',
        'reviewed_at',
        'submitted_for_review_at',
        'submitted_for_approval_at',
        'approval_due_at',
        'approved_by',
        'approved_by_employee_id',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'returned_at',
        'finalized_by',
        'finalized_at',
        'issued_at',
        'supersedes_decision_id',
    ];

    protected function casts(): array
    {
        return [
            'decision_type' => GrievanceDecisionType::class,
            'status' => GrievanceDecisionStatus::class,
            'requires_executive_approval' => 'bool',
            'corrective_action_required' => 'bool',
            'disciplinary_referral_recommended' => 'bool',
            'quorum_met' => 'bool',
            'version_no' => 'int',
            'reviewed_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'submitted_for_approval_at' => 'datetime',
            'approval_due_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'returned_at' => 'datetime',
            'finalized_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function approvalRule(): BelongsTo
    {
        return $this->belongsTo(GrievanceApprovalRule::class, 'approval_rule_id');
    }

    public function approverPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'approver_position_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(GrievanceDecisionApproval::class, 'decision_id')->orderBy('acted_at');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(GrievanceDecisionVote::class, 'decision_id');
    }

    public function letters(): HasMany
    {
        return $this->hasMany(GrievanceLetter::class, 'decision_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status?->value, self::EDITABLE, true);
    }
}
