<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One estimate of a plan (brief §50–53): a unit (or proposed unit) ×
 * occupation × optional work item, with the inputs, the standard and the
 * methodology version it was calculated with, the DECIMAL FTE and the
 * trace. The planner may override the headcount with a reason.
 */
class ManpowerPlanLine extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'plan_id', 'organization_id', 'organization_unit_id', 'unit_design_proposal_id', 'activity_id', 'task_id', 'occupation_id', 'profession_id',
        'methodology_id', 'methodology_version_no', 'standard_id', 'inputs', 'calculated_fte', 'recommended_headcount', 'approved_headcount',
        'adjustment_reason', 'adjusted_by', 'calculation_trace', 'calculated_at', 'position_requirement_id', 'notes', 'sequence',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array', 'calculation_trace' => 'array', 'calculated_fte' => 'decimal:4', 'recommended_headcount' => 'integer', 'approved_headcount' => 'integer',
            'methodology_version_no' => 'integer', 'sequence' => 'integer', 'calculated_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ManpowerPlan::class, 'plan_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(UnitDesignProposal::class, 'unit_design_proposal_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProcessActivity::class, 'activity_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProcessTask::class, 'task_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function methodology(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlanningMethodology::class, 'methodology_id');
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(PositionServiceTaskStandard::class, 'standard_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(PositionRequirement::class, 'position_requirement_id');
    }

    /** The headcount the plan carries for this line: the planner's override, else the rounded estimate. */
    public function headcount(): ?int
    {
        return $this->approved_headcount ?? $this->recommended_headcount;
    }
}
