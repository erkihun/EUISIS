<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Court\Domain\Models\Concerns\AuditableContract as AuditableContract;
use Modules\Court\Domain\Models\Concerns\Auditable;

class Letter extends Model implements AuditableContract
{
    protected $table = 'court_letters';

    use HasFactory, Auditable;

    protected $fillable = [
        'letter_template_id',
        'user_id',
        'recipient_name',
        'recipient_title',
        'recipient_company',
        'subject',
        'reference_number',
        'case_number',
        'body',
        'cc',
        'send_to_applicant',
        'send_to_respondent',
        'approved_by_name',
        'approved_by_title',
        'approval_status',
    ];

    protected $casts = [
        'send_to_applicant'  => 'boolean',
        'send_to_respondent' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
