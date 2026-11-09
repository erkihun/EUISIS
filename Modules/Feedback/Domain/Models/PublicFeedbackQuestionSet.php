<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionSetStatus;

/**
 * One version of a public feedback questionnaire (docs/organization-public-feedback-form.md).
 *
 * Versions of the same questionnaire share a `code`. A draft is edited
 * freely; a published version is frozen — submissions record the version
 * they answered (employee_service_feedback.question_set_id), so its wording
 * must never change under them. A change is a new draft, published in turn,
 * which retires the old version without deleting it.
 */
class PublicFeedbackQuestionSet extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_question_sets';

    protected $fillable = [
        'code',
        'version_no',
        'name_en',
        'name_am',
        'status',
        'published_at',
        'published_by',
        'retired_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => PublicFeedbackQuestionSetStatus::class,
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @return HasMany<PublicFeedbackQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(PublicFeedbackQuestion::class, 'question_set_id')->orderBy('sort_order')->orderBy('code');
    }

    public function isDraft(): bool
    {
        return $this->status === PublicFeedbackQuestionSetStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === PublicFeedbackQuestionSetStatus::Published;
    }

    public function localizedName(string $locale): string
    {
        return $locale === 'am' && filled($this->name_am) ? (string) $this->name_am : (string) $this->name_en;
    }
}
