<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceSlaDayType;
use App\Enums\Grievance\GrievanceSlaStartPoint;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One handler level a case passed through (docs/grievance-management.md §5).
 * The SLA is snapshotted here when the stage is created, so a later policy
 * change never rewrites a historical deadline. A stage has at most one
 * successor (unique from_stage_id), which is what makes a double escalation
 * or double appeal impossible at the database level.
 */
class GrievanceCaseStage extends Model
{
    use HasUuidPrimaryKey;

    /** Stage statuses in which the handler still owes a decision. */
    public const OPEN_STATUSES = ['pending', 'received', 'under_review', 'awaiting_information', 'hearing_scheduled', 'decision_drafting', 'pending_approval', 'returned_for_correction'];

    protected $fillable = [
        'grievance_id',
        'stage_no',
        'handler_type',
        'handler_id',
        'organization_id',
        'organization_unit_id',
        'committee_id',
        'external_authority_id',
        'route_id',
        'from_stage_id',
        'movement_type',
        'movement_reason',
        'moved_by',
        'status',
        'received_at',
        'review_started_at',
        'sla_profile_id',
        'sla_days',
        'sla_day_type',
        'sla_start_point',
        'sla_started_at',
        'due_at',
        'original_due_at',
        'paused_days',
        'auto_escalate',
        'warnings_sent',
        'decision_id',
        'completed_at',
        'escalated_at',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'handler_type' => GrievanceHandlerType::class,
            'movement_type' => GrievanceMovementType::class,
            'status' => GrievanceStageStatus::class,
            'sla_day_type' => GrievanceSlaDayType::class,
            'sla_start_point' => GrievanceSlaStartPoint::class,
            'received_at' => 'datetime',
            'review_started_at' => 'datetime',
            'sla_started_at' => 'datetime',
            'due_at' => 'datetime',
            'original_due_at' => 'datetime',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'auto_escalate' => 'bool',
            'is_current' => 'bool',
            'warnings_sent' => 'array',
            'stage_no' => 'int',
            'sla_days' => 'int',
            'paused_days' => 'int',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }

    public function externalAuthority(): BelongsTo
    {
        return $this->belongsTo(GrievanceExternalAuthority::class, 'external_authority_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(GrievanceRoute::class, 'route_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'from_stage_id');
    }

    public function slaProfile(): BelongsTo
    {
        return $this->belongsTo(GrievanceSlaProfile::class, 'sla_profile_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GrievanceStageMember::class, 'case_stage_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->eligible();
    }

    public function officers(): HasMany
    {
        return $this->hasMany(GrievanceCaseOfficer::class, 'case_stage_id');
    }

    public function activeOfficers(): HasMany
    {
        return $this->officers()->whereNull('released_at');
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(GrievanceSlaPause::class, 'case_stage_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(GrievanceDecision::class, 'case_stage_id');
    }

    public function recusals(): HasMany
    {
        return $this->hasMany(GrievanceCaseRecusal::class, 'case_stage_id');
    }

    public function isOpen(): bool
    {
        return $this->is_current && in_array($this->status?->value, self::OPEN_STATUSES, true);
    }

    public function isPaused(): bool
    {
        return $this->pauses()->where('status', 'active')->exists();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_current', true)->whereIn('status', self::OPEN_STATUSES);
    }
}
