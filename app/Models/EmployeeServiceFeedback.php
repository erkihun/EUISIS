<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedOrPlaintext;
use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicIntakeChannel;
use App\Enums\PublicServiceTimeBand;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * One public submission — feedback, suggestion or complaint — about exactly
 * one target: the employee whose card QR was scanned, or the organization
 * whose feedback QR was scanned. The table name predates organization
 * targets; the rows do not.
 */
class EmployeeServiceFeedback extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_service_feedback';

    protected $fillable = [
        'employee_id',
        'organization_target_id',
        'target_type',
        'source_type',
        'source_organization_qr_id',
        'target_name_snapshot',
        'target_code_snapshot',
        'subject',
        'submitter_identity_mode',
        'submitted_at',
        'employee_feedback_token_id',
        'employee_assignment_id',
        'type',
        'reference_number',
        'incident_date',
        'incident_location',
        'organization_id',
        'organization_unit_id',
        'organization_unit_name_snapshot',
        'organization_unit_name_am_snapshot',
        'organization_unit_type_snapshot',
        'unit_selection_mode',
        'position_id',
        'position_service_id',
        'service_no_snapshot',
        'service_name_snapshot',
        'question_set_id',
        'service_id',
        'service_text',
        'service_date',
        'service_time_band',
        'rating',
        'comment',
        'client_name',
        'client_contact',
        'client_email',
        'submitter_organization',
        'submitter_address',
        'tip_type',
        'intake_channel',
        'involved_parties',
        'ip_address',
        'user_agent',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    /**
     * Submission metadata is kept for abuse investigation only. Hiding it here
     * keeps it out of any Inertia payload built by serialising the model, so a
     * client's IP never reaches a browser by accident.
     */
    protected $hidden = [
        'ip_address',
        'user_agent',
        // Who sent it is a separate grant (PublicFeedbackVisibility::IDENTITY); screens add it explicitly.
        'client_name',
        'client_contact',
        'client_email',
        'submitter_organization',
        'submitter_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'type' => ServiceFeedbackType::class,
            'target_type' => PublicFeedbackTargetType::class,
            'source_type' => PublicFeedbackSourceType::class,
            'submitter_identity_mode' => SubmitterIdentityMode::class,
            // Encrypted at rest (brief §35); rows written before encryption read through.
            'client_contact' => EncryptedOrPlaintext::class,
            // Tipster/submitter details added with Tip-offs: encrypted from the first row.
            'client_email' => 'encrypted',
            'submitter_address' => 'encrypted:array',
            'tip_type' => PublicTipType::class,
            'intake_channel' => PublicIntakeChannel::class,
            'rating' => 'integer',
            'incident_date' => 'date',
            'service_date' => 'date',
            'service_time_band' => PublicServiceTimeBand::class,
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // PHP's copy of the PostgreSQL CHECK constraints: every driver, every path.
        static::saving(function (self $submission): void {
            $submission->assertTargetIntegrity();
            $submission->assertTipTypeIntegrity();
            $submission->assertServiceContextIntegrity();
        });
    }

    /**
     * Exactly one primary target, produced by a matching source (brief §23, 24).
     * Rows written before the unified model carry no target columns yet and
     * are employee submissions from the card QR, which the defaults say.
     */
    public function assertTargetIntegrity(): void
    {
        $target = $this->target_type ?? PublicFeedbackTargetType::Employee;
        $source = $this->source_type ?? PublicFeedbackSourceType::EmployeeCardQr;
        $hasEmployee = $this->employee_id !== null;
        $hasOrganization = $this->organization_target_id !== null;

        $valid = match ($target) {
            PublicFeedbackTargetType::Employee => $hasEmployee && ! $hasOrganization,
            PublicFeedbackTargetType::Organization => $hasOrganization && ! $hasEmployee,
        };

        if (! $valid) {
            throw new InvalidArgumentException('A public submission names exactly one target: an employee or an organization.');
        }

        if ($source->targetType() !== $target) {
            throw new InvalidArgumentException('The submission source does not produce this target type.');
        }
    }

    /** A tip type belongs to a Tip-off (a `suggestion` row) only — the PHP copy of esf_tip_type_chk. */
    public function assertTipTypeIntegrity(): void
    {
        if ($this->tip_type !== null && $this->type !== ServiceFeedbackType::Suggestion) {
            throw new InvalidArgumentException('Only a Tip-off carries a tip type.');
        }
    }

    /**
     * Service information and questionnaire answers belong to an organization
     * submission — the PHP copy of esf_org_service_context_chk. An employee
     * submission takes its service from the employee's post instead.
     */
    public function assertServiceContextIntegrity(): void
    {
        if ($this->isEmployeeTarget() && ($this->service_id !== null || $this->service_text !== null || $this->question_set_id !== null)) {
            throw new InvalidArgumentException('Service information and questionnaire answers belong to an organization submission only.');
        }
    }

    public function isTipOff(): bool
    {
        return $this->type === ServiceFeedbackType::Suggestion;
    }

    public function isEmployeeTarget(): bool
    {
        return ($this->target_type ?? PublicFeedbackTargetType::Employee) === PublicFeedbackTargetType::Employee;
    }

    /** The questionnaire version this submission answered; null when it answered none. */
    public function questionSet(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackQuestionSet::class, 'question_set_id');
    }

    /** @return HasMany<PublicFeedbackAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(PublicFeedbackAnswer::class, 'submission_id');
    }

    /** The institution's catalog service the visitor received (organization target). */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The organization a submission is ABOUT (organization QR); not the employee's office context. */
    public function organizationTarget(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_target_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    /**
     * The relevant work unit as it was named when this submission was made.
     * Null for rows older than the snapshot columns and for "General / Not sure"
     * (unit_selection_mode GENERAL), which deliberately names no unit.
     */
    public function relevantUnitName(string $locale): ?string
    {
        if ($locale === 'am' && filled($this->organization_unit_name_am_snapshot)) {
            return $this->organization_unit_name_am_snapshot;
        }

        return $this->organization_unit_name_snapshot;
    }

    /** The visitor explicitly answered "General / Not sure" instead of naming a unit. */
    public function isGeneralUnitSelection(): bool
    {
        return $this->unit_selection_mode === 'GENERAL';
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The service delivered, from the position's own catalog.
     *
     * NOT ServiceType — that model lists entitlements an employee receives,
     * which is unrelated to the work a client rates here.
     */
    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(EmployeeFeedbackToken::class, 'employee_feedback_token_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    /** Append-only transitions, oldest first. */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(PublicFeedbackStatusHistory::class, 'submission_id')->orderBy('transitioned_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PublicFeedbackAssignment::class, 'submission_id')->orderBy('assigned_at');
    }

    /** Employees an internal review named as involved (brief §61); never the target. */
    public function subjects(): HasMany
    {
        return $this->hasMany(PublicFeedbackSubjectEmployee::class, 'submission_id')->orderBy('added_at');
    }

    /** Controlled referral requests to the discipline domain (brief §59). */
    public function referrals(): HasMany
    {
        return $this->hasMany(PublicFeedbackReferral::class, 'submission_id')->orderBy('requested_at');
    }

    /** The open hand-over; at most one exists (unique partial index). */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(PublicFeedbackAssignment::class, 'submission_id')->whereNull('ended_at')->orderByDesc('assigned_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeServiceFeedbackAttachment::class, 'employee_service_feedback_id');
    }

    /** Entries an employee or a report may surface; excludes suppressed comments. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', ServiceFeedbackStatus::Hidden->value);
    }

    public function scopeLowRated(Builder $query, int $threshold = 2): Builder
    {
        return $query->where('rating', '<=', $threshold);
    }
}
