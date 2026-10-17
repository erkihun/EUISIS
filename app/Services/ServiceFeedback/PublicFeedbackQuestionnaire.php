<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackQuestionSetStatus;
use App\Enums\PublicFeedbackQuestionType;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\PublicFeedbackAnswer;
use App\Models\PublicFeedbackQuestion;
use App\Models\PublicFeedbackQuestionSet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use WeakMap;

/**
 * The published organization feedback questionnaire, as the public form and
 * the submission see it (docs/organization-public-feedback-form.md §4).
 *
 * The questions are data, not code: an administrator drafts and publishes a
 * version (PublicFeedbackQuestionnaireAdmin), the page renders the published
 * version's questions for the chosen submission type, and the same version
 * builds the validation rules and stores the answers. With no published
 * version the form simply has no questionnaire — the core fields still work.
 *
 * The browser keys answers by the question's stable code and states the
 * version it rendered. If a newer version was published while the form was
 * open, the submission is refused with "the form was updated" and the page
 * reloads the new version; answers are never filed against wording the
 * visitor did not see.
 */
final class PublicFeedbackQuestionnaire
{
    /** The one questionnaire of the organization QR form. */
    public const ORGANIZATION_FORM = 'ORGANIZATION_SERVICE_FEEDBACK';

    /**
     * The version read for each request. Keyed by the request object itself, not
     * its id: this service is scoped, but a controller holding it can outlive one
     * request, and an object id is reused once its request is freed.
     *
     * @var WeakMap<Request, PublicFeedbackQuestionSet|null>
     */
    private WeakMap $loaded;

    public function __construct()
    {
        $this->loaded = new WeakMap;
    }

    /**
     * The version visitors answer now, with its questions and options; null
     * when none is published. Read once per request — validation, rendering
     * and storage of one request all see the same version.
     */
    public function published(): ?PublicFeedbackQuestionSet
    {
        $request = request();

        if (! $this->loaded->offsetExists($request)) {
            $this->loaded[$request] = PublicFeedbackQuestionSet::query()
                ->where('code', self::ORGANIZATION_FORM)
                ->where('status', PublicFeedbackQuestionSetStatus::Published->value)
                ->with('questions.options')
                ->first();
        }

        return $this->loaded[$request];
    }

    /** @return Collection<int, PublicFeedbackQuestion> */
    public function questionsFor(?PublicFeedbackQuestionSet $set, ServiceFeedbackType $type): Collection
    {
        if ($set === null) {
            return collect();
        }

        return $set->questions->filter(fn (PublicFeedbackQuestion $question): bool => $question->appliesTo($type))->values();
    }

    /**
     * What the public page renders: the version and its questions for this
     * type, in the visitor's language. Null when there is nothing to ask.
     *
     * @return array{version: int, questions: list<array<string, mixed>>}|null
     */
    public function toPublicArray(ServiceFeedbackType $type, string $locale): ?array
    {
        $set = $this->published();
        $questions = $this->questionsFor($set, $type);

        if ($set === null || $questions->isEmpty()) {
            return null;
        }

        return [
            'version' => $set->version_no,
            'questions' => $questions->map(fn (PublicFeedbackQuestion $question): array => [
                'code' => $question->code,
                'section' => $question->section->value,
                'type' => $question->question_type->value,
                'label' => $question->localizedLabel($locale),
                'help' => $question->localizedHelp($locale),
                'required' => $question->is_required,
                'options' => $question->options->map(fn ($option): array => [
                    'code' => $option->code,
                    'label' => $option->localizedLabel($locale),
                ])->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Validation for `questionnaire_version` and `answers.*` of one type.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(ServiceFeedbackType $type): array
    {
        $set = $this->published();
        $questions = $this->questionsFor($set, $type);
        $codes = $questions->pluck('code')->all();

        $rules = [
            /*
             * A page rendered before this version was published states no version. That is
             * refused only when the type now has a required question the visitor never saw;
             * with optional questions only, the submission goes through without answers.
             */
            'questionnaire_version' => [$questions->contains('is_required', true) ? 'required' : 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($set): void {
                if ($value !== null && (int) $value !== $set?->version_no) {
                    $fail(__('service-feedback.validation.questionnaire_updated'));
                }
            }],
            'answers' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) use ($codes): void {
                // An answer to a question this version does not ask, or answers with no version: the page showed other wording.
                if (is_array($value) && $value !== [] && (array_diff(array_map('strval', array_keys($value)), $codes) !== [] || blank(request()->input('questionnaire_version')))) {
                    $fail(__('service-feedback.validation.questionnaire_updated'));
                }
            }],
        ];

        foreach ($questions as $question) {
            $key = 'answers.'.$question->code;
            $presence = $question->is_required ? 'required' : 'nullable';

            $rules += match ($question->question_type) {
                PublicFeedbackQuestionType::Rating => [$key => [$presence, 'integer', 'between:'.PublicFeedbackQuestionType::RATING_MIN.','.PublicFeedbackQuestionType::RATING_MAX]],
                PublicFeedbackQuestionType::YesNo => [$key => [$presence, 'string', Rule::in(['yes', 'no'])]],
                PublicFeedbackQuestionType::SingleChoice => [$key => [$presence, 'string', Rule::in($question->options->pluck('code')->all())]],
                PublicFeedbackQuestionType::MultipleChoice => [
                    $key => [$presence, 'array', ...($question->is_required ? ['min:1'] : []), 'max:'.max(1, $question->options->count())],
                    $key.'.*' => ['string', 'distinct', Rule::in($question->options->pluck('code')->all())],
                ],
                PublicFeedbackQuestionType::ShortText => [$key => [$presence, 'string', 'max:'.PublicFeedbackQuestionType::SHORT_TEXT_MAX]],
                PublicFeedbackQuestionType::LongText => [$key => [$presence, 'string', 'max:'.PublicFeedbackQuestionType::LONG_TEXT_MAX]],
            };
        }

        return $rules;
    }

    /**
     * Each question's label as the validation attribute, so a message names the question.
     *
     * @return array<string, string>
     */
    public function attributes(ServiceFeedbackType $type, string $locale): array
    {
        $attributes = [];

        foreach ($this->questionsFor($this->published(), $type) as $question) {
            $attributes['answers.'.$question->code] = $question->localizedLabel($locale);
            $attributes['answers.'.$question->code.'.*'] = $question->localizedLabel($locale);
        }

        return $attributes;
    }

    /**
     * The validated answers as rows to store, one per answer (one per chosen
     * option of a multiple-choice question). Unanswered optional questions
     * produce no row.
     *
     * @param  array<string, mixed>  $answers  validated `answers`
     * @return list<array<string, mixed>>
     */
    public function rowsFor(PublicFeedbackQuestionSet $set, ServiceFeedbackType $type, array $answers): array
    {
        $rows = [];

        foreach ($this->questionsFor($set, $type) as $question) {
            $value = $answers[$question->code] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $base = [
                'question_set_id' => $set->getKey(),
                'question_id' => $question->getKey(),
                'question_code' => $question->code,
                'question_type' => $question->question_type->value,
                'option_id' => null,
                'rating_value' => null,
                'bool_value' => null,
                'text_value' => null,
            ];
            $options = $question->options->keyBy('code');

            switch ($question->question_type) {
                case PublicFeedbackQuestionType::Rating:
                    $rows[] = ['rating_value' => (int) $value] + $base;
                    break;
                case PublicFeedbackQuestionType::YesNo:
                    $rows[] = ['bool_value' => $value === 'yes'] + $base;
                    break;
                case PublicFeedbackQuestionType::SingleChoice:
                    $rows[] = ['option_id' => $options->get((string) $value)?->getKey()] + $base;
                    break;
                case PublicFeedbackQuestionType::MultipleChoice:
                    foreach (array_unique(array_map('strval', (array) $value)) as $code) {
                        $rows[] = ['option_id' => $options->get($code)?->getKey()] + $base;
                    }
                    break;
                case PublicFeedbackQuestionType::ShortText:
                case PublicFeedbackQuestionType::LongText:
                    $text = trim((string) $value);
                    if ($text !== '') {
                        $rows[] = ['text_value' => $text] + $base;
                    }
                    break;
            }
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! in_array($row['question_type'], ['single_choice', 'multiple_choice'], true) || $row['option_id'] !== null,
        ));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function persist(EmployeeServiceFeedback $entry, array $rows): void
    {
        foreach ($rows as $row) {
            PublicFeedbackAnswer::query()->create(['submission_id' => $entry->getKey()] + $row);
        }
    }

    /**
     * The service information and answers of an organization submission, for
     * staff detail screens; null for an employee submission or a row with
     * none of it (submissions made before the organization form).
     *
     * @return array<string, mixed>|null
     */
    public function presentForStaff(EmployeeServiceFeedback $entry, string $locale): ?array
    {
        if ($entry->isEmployeeTarget()) {
            return null;
        }

        $entry->loadMissing(['organizationUnit:id,name_en,name_am', 'questionSet:id,version_no']);
        $unit = $entry->organizationUnit;
        $answers = $entry->question_set_id === null ? [] : $this->presentAnswers($entry, $locale);
        $details = [
            'service' => $entry->service_name_snapshot ?? $entry->service_text,
            'service_code' => $entry->service_id !== null ? $entry->service_no_snapshot : null,
            // The visitor's own words: not linked to the institution's service catalog.
            'service_unstructured' => $entry->service_id === null && $entry->service_text !== null,
            'service_point' => $unit === null ? null : ($locale === 'am' && filled($unit->name_am) ? $unit->name_am : $unit->name_en),
            'service_date' => $entry->service_date?->toDateString(),
            'service_time_band' => $entry->service_time_band?->value,
            'questionnaire_version' => $entry->questionSet?->version_no,
            'answers' => $answers,
        ];

        return array_filter($details, fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false) === [] ? null : $details;
    }

    /**
     * The answers of one submission for staff screens: the question as the
     * visitor saw it (the frozen version's wording) and the answer.
     *
     * @return list<array{code: string, question: string, type: string, rating: int|null, value: string|null}>
     */
    public function presentAnswers(EmployeeServiceFeedback $entry, string $locale): array
    {
        $answers = $entry->answers()->with(['question', 'option'])->get();

        return $answers->groupBy('question_id')
            ->sortBy(fn (Collection $group): int => (int) $group->first()?->question?->sort_order)
            ->map(function (Collection $group) use ($locale): array {
                /** @var PublicFeedbackAnswer $first */
                $first = $group->first();
                $question = $first->question;
                $value = match ($first->question_type) {
                    PublicFeedbackQuestionType::Rating => null,
                    PublicFeedbackQuestionType::YesNo => $first->bool_value ? 'yes' : 'no',
                    PublicFeedbackQuestionType::SingleChoice, PublicFeedbackQuestionType::MultipleChoice => $group
                        ->map(fn (PublicFeedbackAnswer $answer): string => (string) $answer->option?->localizedLabel($locale))
                        ->filter()->implode(', '),
                    default => $first->text_value,
                };

                return [
                    'code' => $first->question_code,
                    'question' => $question?->localizedLabel($locale) ?? $first->question_code,
                    'type' => $first->question_type->value,
                    'rating' => $first->rating_value,
                    'value' => $value,
                ];
            })->values()->all();
    }
}
