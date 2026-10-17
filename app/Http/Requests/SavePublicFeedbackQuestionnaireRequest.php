<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PublicFeedbackQuestionSection;
use App\Enums\PublicFeedbackQuestionType;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaireAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A questionnaire draft, saved whole. Codes are lower-case identifiers so they
 * can travel as form keys and stay stable across versions; Amharic wording may
 * be left empty while drafting and is required at publication
 * (PublicFeedbackQuestionnaireAdmin::publish).
 */
class SavePublicFeedbackQuestionnaireRequest extends FormRequest
{
    public const MAX_QUESTIONS = 40;

    public const MAX_OPTIONS = 20;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(PublicFeedbackQuestionnaireAdmin::class)->canManage($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $code = ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'];

        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'questions' => ['present', 'array', 'max:'.self::MAX_QUESTIONS],
            'questions.*.code' => $code,
            'questions.*.section' => ['required', 'string', Rule::in(PublicFeedbackQuestionSection::values())],
            'questions.*.question_type' => ['required', 'string', Rule::in(PublicFeedbackQuestionType::values())],
            'questions.*.label_en' => ['required', 'string', 'max:300'],
            'questions.*.label_am' => ['nullable', 'string', 'max:300'],
            'questions.*.help_en' => ['nullable', 'string', 'max:500'],
            'questions.*.help_am' => ['nullable', 'string', 'max:500'],
            'questions.*.is_required' => ['boolean'],
            'questions.*.submission_types' => ['required', 'array', 'min:1'],
            'questions.*.submission_types.*' => ['string', Rule::in(['feedback', 'suggestion', 'complaint'])],
            'questions.*.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'questions.*.options.*.code' => $code,
            'questions.*.options.*.label_en' => ['required', 'string', 'max:200'],
            'questions.*.options.*.label_am' => ['nullable', 'string', 'max:200'],
        ];
    }
}
