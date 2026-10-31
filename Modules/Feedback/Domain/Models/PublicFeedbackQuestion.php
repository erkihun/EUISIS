<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionSection;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionType;

/**
 * One question of one questionnaire version. `code` is stable across
 * versions (the same question keeps its code when its wording is revised),
 * so answers can be followed over time; the wording itself is frozen with
 * the published version it belongs to.
 */
class PublicFeedbackQuestion extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_questions';

    protected $fillable = [
        'question_set_id',
        'code',
        'section',
        'question_type',
        'label_en',
        'label_am',
        'help_en',
        'help_am',
        'is_required',
        'submission_types',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'section' => PublicFeedbackQuestionSection::class,
            'question_type' => PublicFeedbackQuestionType::class,
            'is_required' => 'boolean',
            'submission_types' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function questionSet(): BelongsTo
    {
        return $this->belongsTo(PublicFeedbackQuestionSet::class, 'question_set_id');
    }

    /** @return HasMany<PublicFeedbackQuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PublicFeedbackQuestionOption::class, 'question_id')->orderBy('sort_order')->orderBy('code');
    }

    public function appliesTo(ServiceFeedbackType $type): bool
    {
        return in_array($type->value, (array) $this->submission_types, true);
    }

    public function localizedLabel(string $locale): string
    {
        return $locale === 'am' && filled($this->label_am) ? (string) $this->label_am : (string) $this->label_en;
    }

    public function localizedHelp(string $locale): ?string
    {
        $help = $locale === 'am' && filled($this->help_am) ? $this->help_am : $this->help_en;

        return filled($help) ? (string) $help : null;
    }
}
