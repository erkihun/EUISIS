<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicFeedbackQuestionType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one questionnaire question, in a typed column so it can be
 * aggregated: `rating_value` (1–5), `bool_value` (yes/no), `option_id`
 * (one row per chosen option of a choice question) or `text_value`.
 * Written once with the submission, never edited.
 */
class PublicFeedbackAnswer extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'public_feedback_answers';

    protected $fillable = [
        'submission_id',
        'question_set_id',
        'question_id',
        'question_code',
        'question_type',
        'option_id',
        'rating_value',
        'bool_value',
        'text_value',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => PublicFeedbackQuestionType::class,
            'rating_value' => 'integer',
            'bool_value' => 'boolean',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackQuestion::class, 'question_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackQuestionOption::class, 'option_id');
    }
}
