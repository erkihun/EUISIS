<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievancePriority;
use App\Enums\Grievance\GrievanceRecordState;
use App\Enums\GrievanceOriginLevel;
use App\Enums\GrievanceStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A formal employee grievance case (docs/grievance-management.md).
 *
 * `reference_number` IS the case number (stable, never regenerated);
 * `organization_id` / `organization_unit_id` are the complainant's origin;
 * `category_id` is the grievance category. Handling happens in stages
 * (GrievanceCaseStage); `current_stage_id` and `current_handler_*` are a
 * denormalized pointer to the open one for fast listing and access checks.
 */
class Grievance extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'reference_number',
        'submitted_by_user_id',
        'employee_id',
        'employee_assignment_id',
        'organization_id',
        'organization_unit_id',
        'origin_level',
        'category_id',
        'subject',
        'description',
        'incident_date',
        'priority',
        'confidentiality_level',
        'status',
        'current_stage_id',
        'current_handler_type',
        'current_handler_id',
        'current_assigned_type',
        'current_assigned_id',
        'submitted_at',
        'accepted_at',
        'resolved_at',
        'requirement_checked_at',
        'requirement_fulfilled',
        'requirement_notes',
        'intake_reason_code',
        'intake_notes',
        'withdraw_requested_at',
        'withdrawn_at',
        'withdrawal_reason',
        'withdrawal_reason_code',
        'closed_at',
        'closed_by',
        'closure_reason_code',
        'closure_notes',
        'record_state',
        'archived_at',
        'legal_hold',
        'legal_hold_reason',
        'retention_until',
        'appeal_deadline_at',
        'respondent_type',
        'respondent_employee_id',
        'respondent_organization_unit_id',
        'respondent_description',
        'root_cause_category',
        'systemic_issue_flag',
        'corrective_action_required',
        'reopened_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceStatus::class,
            'origin_level' => GrievanceOriginLevel::class,
            'priority' => GrievancePriority::class,
            'confidentiality_level' => GrievanceConfidentiality::class,
            'current_handler_type' => GrievanceHandlerType::class,
            'record_state' => GrievanceRecordState::class,
            'requirement_fulfilled' => 'bool',
            'legal_hold' => 'bool',
            'systemic_issue_flag' => 'bool',
            'corrective_action_required' => 'bool',
            'reopened_count' => 'int',
            'incident_date' => 'date',
            'retention_until' => 'date',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'requirement_checked_at' => 'datetime',
            'withdraw_requested_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'appeal_deadline_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function respondentEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'respondent_employee_id');
    }

    public function respondentOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'respondent_organization_unit_id');
    }

    /** @deprecated First-module pointer; use currentStage(). */
    public function currentAssigned(): MorphTo
    {
        return $this->morphTo('current_assigned');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(GrievanceCaseStage::class)->orderBy('stage_no');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'current_stage_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(GrievanceDecision::class);
    }

    public function letters(): HasMany
    {
        return $this->hasMany(GrievanceLetter::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GrievanceEvidence::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(GrievanceInformationRequest::class);
    }

    public function hearings(): HasMany
    {
        return $this->hasMany(GrievanceHearing::class);
    }

    public function minutes(): HasMany
    {
        return $this->hasMany(GrievanceMinutes::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(GrievanceAppeal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GrievanceCaseEvent::class)->orderBy('occurred_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(GrievanceNote::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(GrievanceTask::class);
    }

    public function officers(): HasMany
    {
        return $this->hasMany(GrievanceCaseOfficer::class);
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(GrievanceCorrectiveAction::class);
    }

    public function disciplinaryReferrals(): HasMany
    {
        return $this->hasMany(GrievanceDisciplinaryReferral::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(GrievanceAmendment::class);
    }

    public function recusals(): HasMany
    {
        return $this->hasMany(GrievanceCaseRecusal::class);
    }

    // ── First-module relations (kept for history; not written any more) ─────

    public function assignments(): HasMany
    {
        return $this->hasMany(GrievanceAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(GrievanceAssignment::class)->where('is_current', true);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(GrievanceResponse::class);
    }

    public function latestResponse(): HasOne
    {
        // Ordered, not latestOfMany(): PostgreSQL has no MAX for uuid keys.
        return $this->hasOne(GrievanceResponse::class)->latest('created_at')->latest('id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(GrievanceEscalation::class);
    }

    public function decisionLetter(): HasOne
    {
        return $this->hasOne(GrievanceDecisionLetter::class);
    }

    public function tribunalCase(): HasOne
    {
        return $this->hasOne(AdministrativeTribunalCase::class);
    }

    /**
     * The complainant: the user who filed it, or the user linked to the
     * complainant employee record.
     */
    public function isOwnedBy(User $user): bool
    {
        if ($this->submitted_by_user_id !== null && (int) $this->submitted_by_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->employee_id !== null && $user->employee_id !== null && $this->employee_id === $user->employee_id;
    }

    public function isDraft(): bool
    {
        return $this->status === GrievanceStatus::Draft;
    }

    public function isFinal(): bool
    {
        return $this->status?->isFinal() ?? false;
    }
}
