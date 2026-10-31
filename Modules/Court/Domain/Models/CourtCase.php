<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtStaffMember as User;

class CourtCase extends Model
{
    protected $table = 'court_cases';
    protected $guarded = [];        // or list explicit fillables if you prefer
    public $timestamps = true;      // if your table has created_at/updated_at
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'first_hearing_date' => 'date',
        'filing_date' => 'date',
    ];

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id');
    }

    public function caseType(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CaseFile::class, 'case_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(CaseEvidence::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(CaseWitness::class, 'case_id');
    }
}
