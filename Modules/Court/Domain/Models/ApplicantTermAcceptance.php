<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantTermAcceptance extends Model
{
    protected $table = 'court_applicant_term_acceptances';

    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'terms_and_condition_id',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function terms(): BelongsTo
    {
        return $this->belongsTo(TermsAndCondition::class, 'terms_and_condition_id');
    }
}
