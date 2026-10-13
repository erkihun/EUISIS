<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinarySeverity;
use App\Enums\Discipline\DisciplinaryTrack;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An employee disciplinary case (Regulation 201/2018 Parts Two–Three). It is
 * never a grievance or a public complaint: a public complaint or grievance
 * referral may be its recorded source, but the source record is not converted.
 *
 * Every column is written by DisciplinaryCaseService; requests never
 * mass-assign status, dates, the accused employee or the committee.
 */
class DisciplinaryCase extends Model
{
    use HasUuidPrimaryKey;

    public const SOURCE_PUBLIC_COMPLAINT = 'public_service_complaint';

    public const SOURCE_GRIEVANCE_REFERRAL = 'grievance_referral';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'track' => DisciplinaryTrack::class,
            'severity' => DisciplinarySeverity::class,
            'status' => DisciplinaryCaseStatus::class,
            'in_absentia' => 'boolean',
            'additional_evidence_required' => 'boolean',
            'final_statement_declined' => 'boolean',
            'charged_at' => 'datetime',
            'summons_served_at' => 'datetime',
            'hearing_at' => 'datetime',
            'final_statement_at' => 'datetime',
            'report_submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'discontinued_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function accused(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accused_employee_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseMember::class, 'case_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(DisciplinaryCharge::class, 'case_id')->orderBy('sequence');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(DisciplinaryResponse::class, 'case_id')->orderBy('version');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisciplinaryEvidence::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(DisciplinaryWitness::class, 'case_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(DisciplinaryRecommendation::class, 'case_id')->orderBy('version');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(DisciplinaryDecision::class, 'case_id')->orderBy('decided_at');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DisciplinaryStatusHistory::class, 'case_id')->orderBy('created_at');
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(StatutoryDeadline::class, 'subject');
    }

    public function latestResponse(): ?DisciplinaryResponse
    {
        return $this->responses()->reorder('version', 'desc')->first();
    }

    public function currentRecommendation(): ?DisciplinaryRecommendation
    {
        return $this->recommendations()->reorder('version', 'desc')->first();
    }

    public function currentDecision(): ?DisciplinaryDecision
    {
        return $this->decisions()->reorder('decided_at', 'desc')->first();
    }
}
