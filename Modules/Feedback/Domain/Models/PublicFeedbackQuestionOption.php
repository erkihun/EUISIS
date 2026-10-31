<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One choice of a single- or multiple-choice questionnaire question. */
class PublicFeedbackQuestionOption extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_question_options';

    protected $fillable = [
        'question_id',
        'code',
        'label_en',
        'label_am',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackQuestion::class, 'question_id');
    }

    public function localizedLabel(string $locale): string
    {
        return $locale === 'am' && filled($this->label_am) ? (string) $this->label_am : (string) $this->label_en;
    }
}
