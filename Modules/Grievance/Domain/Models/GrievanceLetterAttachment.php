<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item listed as attached to an official letter.
 */
class GrievanceLetterAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_letter_attachments';

    protected $fillable = [
        'letter_id',
        'attachment_type',
        'title',
        'evidence_id',
        'reference_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'int',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(GrievanceLetter::class, 'letter_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(GrievanceEvidence::class, 'evidence_id');
    }
}
