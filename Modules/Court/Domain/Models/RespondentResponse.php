<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RespondentResponse extends Model
{
    protected $table = 'court_respondent_responses';

    protected $fillable = [
        'respondent_id',
        'case_number',
        'title',
        'description',
        'pdf_path',
        'response_number',
        'review_status',
        'review_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    public static function nextResponseNumberForCase(string $caseNumber, ?int $excludeResponseId = null): string
    {
        $query = static::query()->where('case_number', $caseNumber);

        if ($excludeResponseId !== null) {
            $query->whereKeyNot($excludeResponseId);
        }

        /** @var int $existingCount */
        $existingCount = (int) $query->lockForUpdate()->count();

        if ($existingCount === 0) {
            return "\u{1218}/{$caseNumber}";
        }

        $sequence = str_pad((string) $existingCount, 2, '0', STR_PAD_LEFT);
        return "\u{1218}/{$caseNumber}/{$sequence}";
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(Respondent::class);
    }
}
