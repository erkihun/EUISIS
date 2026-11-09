<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\StatutoryDeadline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;
use Modules\Discipline\Contracts\Data\DisciplinarySeverity;
use Modules\Discipline\Domain\Enums\DisciplinaryTrack;
use Modules\Grievance\Contracts\ReadModels\GrievanceCommitteeRecord;

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
        return $this->belongsTo(GrievanceCommitteeRecord::class, 'committee_id');
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

    /** Appeals to the Administrative Court against the case's delivered decisions (Part Five). */
    public function appeals(): HasMany
    {
        return $this->hasMany(DisciplinaryAppeal::class, 'case_id');
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(DisciplinarySuspension::class, 'case_id');
    }

    /** The suspension in force today (Art. 12), if any. */
    public function suspensionInForce(): ?DisciplinarySuspension
    {
        return $this->suspensions()->whereNull('lifted_on')->latest('starts_on')->get()
            ->first(fn (DisciplinarySuspension $s) => $s->isInForce());
    }

    /** The appeal of the current decision, if one was noticed. */
    public function currentAppeal(): ?DisciplinaryAppeal
    {
        $decision = $this->currentDecision();

        return $decision === null ? null : $this->appeals()->where('decision_id', $decision->getKey())->first();
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
