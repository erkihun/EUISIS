<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lawyer representing a party of a case, with the power of attorney the
 * registry checks: pending until then, accepted or refused, revoked later.
 */
class CaseRepresentative extends Model
{
    protected $table = 'court_case_representatives';

    protected $fillable = [
        'case_id', 'case_applicant_id', 'lawyer_applicant_id', 'power_of_attorney_path', 'status',
        'confirmed_by_party_at', 'verified_by_user_id', 'verified_at', 'note',
    ];

    protected $casts = [
        'confirmed_by_party_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(CaseApplicant::class, 'case_applicant_id');
    }

    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'lawyer_applicant_id');
    }

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }
}
