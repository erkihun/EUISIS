<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantResponseReply extends Model
{
    protected $table = 'court_applicant_response_replies';

    protected $fillable = [
        'case_id',
        'applicant_id',
        'respondent_response_id',
        'description',
        'pdf_path',
        'review_status',
        'review_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function respondentResponse(): BelongsTo
    {
        return $this->belongsTo(RespondentResponse::class, 'respondent_response_id');
    }
}
