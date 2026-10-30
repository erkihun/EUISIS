<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Domain\Enums\GrievanceRecipientKind;
use Modules\Grievance\Domain\Enums\GrievanceRecipientType;

/**
 * A TO/CC/BCC-internal recipient as it stood when the letter was issued.
 */
class GrievanceLetterRecipient extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_letter_recipients';

    protected $fillable = [
        'letter_id',
        'kind',
        'recipient_type',
        'recipient_id',
        'name',
        'position_title',
        'organization_name',
        'address',
        'email',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => GrievanceRecipientKind::class,
            'recipient_type' => GrievanceRecipientType::class,
            'sort_order' => 'int',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(GrievanceLetter::class, 'letter_id');
    }
}
