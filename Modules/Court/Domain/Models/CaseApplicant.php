<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A party on the applicant's side of a case: the lead applicant, a co-applicant
 * (invited until they confirm), or an intervener (requested until the judge
 * decides). See Modules\Court\Application\Services\CaseParties.
 */
class CaseApplicant extends Model
{
    protected $table = 'court_case_applicants';

    public const ROLE_LEAD = 'lead';

    public const ROLE_CO_APPLICANT = 'co_applicant';

    public const ROLE_INTERVENER = 'intervener';

    /** Statuses that make someone a party who may follow and work on the case. */
    public const PARTY_STATUSES = ['active', 'admitted'];

    protected $fillable = [
        'case_id', 'applicant_id', 'euisis_employee_uuid', 'name', 'role', 'status', 'source', 'statement', 'attachment_path',
        'invited_by_applicant_id', 'merged_from_case_id', 'decided_by_user_id', 'decided_at', 'decision_note', 'responded_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function representatives(): HasMany
    {
        return $this->hasMany(CaseRepresentative::class, 'case_applicant_id');
    }

    public function isParty(): bool
    {
        return in_array($this->status, self::PARTY_STATUSES, true);
    }
}
