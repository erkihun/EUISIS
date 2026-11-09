<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter the administrative tribunal sent an institution through court-ms
 * (Flow 5, ADR 0013). court-ms is the system of record; this keeps the
 * delivered copy (its PDF) for the institution.
 */
class CourtMsLetter extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'court_letter_id',
        'organization_id',
        'case_number',
        'case_title',
        'subject',
        'reference_number',
        'recipient',
        'approved_by',
        'approved_at',
        'pdf_disk',
        'pdf_path',
        'pdf_sha256',
        'received_at',
        'opened_at',
        'opened_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'court_letter_id' => 'integer',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
