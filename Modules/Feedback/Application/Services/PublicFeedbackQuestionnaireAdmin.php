<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionSetStatus;
use Modules\Feedback\Domain\Enums\PublicFeedbackQuestionType;
use Modules\Feedback\Domain\Models\PublicFeedbackQuestion;
use Modules\Feedback\Domain\Models\PublicFeedbackQuestionSet;

/**
 * Drafting, publishing and withdrawing versions of the organization feedback
 * questionnaire (docs/organization-public-feedback-form.md §4).
 *
 * - One draft at a time. A new draft starts as a copy of the published
 *   version, or of the proposed template when nothing was ever published.
 * - A draft is saved whole: its questions are replaced by what was submitted.
 *   No submission refers to a draft, so nothing historical can change.
 * - Publishing freezes the draft and retires the version it replaces in the
 *   same transaction. Retired and published versions are never edited or
 *   deleted: submissions point at them and must keep their wording.
 * - Withdrawing the published version leaves the form without a
 *   questionnaire; the core fields still work.
 *
 * The questionnaire is city-wide, so managing it takes the permission and a
 * city-level account, like the public feedback policy itself.
 */
final class PublicFeedbackQuestionnaireAdmin
{
    public const PERMISSION = 'service_feedback.manage_questions';

    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function canManage(User $user): bool
    {
        return $user->hasAnyPermission([self::PERMISSION]) && $this->scope->isUnrestricted($user);
    }

    /** The current draft, or a new one copied from the published version (or the template). */
    public function createDraft(User $user, ?Request $request = null): PublicFeedbackQuestionSet
    {
        $code = PublicFeedbackQuestionnaire::ORGANIZATION_FORM;

        return DB::transaction(function () use ($user, $request, $code): PublicFeedbackQuestionSet {
            $existing = PublicFeedbackQuestionSet::query()->where('code', $code)->where('status', PublicFeedbackQuestionSetStatus::Draft->value)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $source = PublicFeedbackQuestionSet::query()
                ->where('code', $code)
                ->whereIn('status', [PublicFeedbackQuestionSetStatus::Published->value, PublicFeedbackQuestionSetStatus::Retired->value])
                ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
                ->orderByDesc('version_no')
                ->with('questions.options')
                ->first();

            $draft = PublicFeedbackQuestionSet::query()->create([
                'code' => $code,
                'version_no' => (int) PublicFeedbackQuestionSet::query()->where('code', $code)->max('version_no') + 1,
                'name_en' => $source->name_en ?? PublicFeedbackQuestionnaireTemplate::name()['name_en'],
                'name_am' => $source->name_am ?? PublicFeedbackQuestionnaireTemplate::name()['name_am'],
                'status' => PublicFeedbackQuestionSetStatus::Draft,
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ]);

            $this->writeQuestions($draft, $source === null
                ? PublicFeedbackQuestionnaireTemplate::questions()
                : $source->questions->map(fn (PublicFeedbackQuestion $question): array => $this->questionData($question))->all());

            $this->auditChange($user, $draft, 'draft created'.($source === null ? ' from the proposed template' : ' from version '.$source->version_no), $request);

            return $draft;
        });
    }

    /**
     * Replace the draft's name and questions with what was submitted (validated by the request).
     *
     * @param  array{name_en: string, name_am?: string|null, questions: list<array<string, mixed>>}  $data
     */
    public function saveDraft(User $user, PublicFeedbackQuestionSet $set, array $data, ?Request $request = null): void
    {
        $this->assertDraft($set);
        $this->assertUniqueCodes($data['questions']);

        DB::transaction(function () use ($user, $set, $data, $request): void {
            $set->forceFill([
                'name_en' => $data['name_en'],
                'name_am' => $data['name_am'] ?? null,
                'updated_by' => $user->getKey(),
            ])->save();

            $set->questions()->delete();
            $this->writeQuestions($set, $data['questions']);

            $this->auditChange($user, $set, 'draft saved', $request);
        });
    }

    public function publish(User $user, PublicFeedbackQuestionSet $set, ?Request $request = null): void
    {
        $this->assertDraft($set);
        $set->load('questions.options');
        $this->assertPublishable($set);

        DB::transaction(function () use ($user, $set, $request): void {
            $previous = PublicFeedbackQuestionSet::query()
                ->where('code', $set->code)
                ->where('status', PublicFeedbackQuestionSetStatus::Published->value)
                ->lockForUpdate()
                ->first();

            $previous?->forceFill(['status' => PublicFeedbackQuestionSetStatus::Retired, 'retired_at' => now(), 'updated_by' => $user->getKey()])->save();

            $set->forceFill([
                'status' => PublicFeedbackQuestionSetStatus::Published,
                'published_at' => now(),
                'published_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ])->save();

            $this->auditChange($user, $set, 'published'.($previous !== null ? ', replacing version '.$previous->version_no : ''), $request);
        });
    }

    public function retire(User $user, PublicFeedbackQuestionSet $set, ?Request $request = null): void
    {
        if (! $set->isPublished()) {
            throw ValidationException::withMessages(['questionnaire' => __('service-feedback.questionnaire_not_published')]);
        }

        $set->forceFill(['status' => PublicFeedbackQuestionSetStatus::Retired, 'retired_at' => now(), 'updated_by' => $user->getKey()])->save();
        $this->auditChange($user, $set, 'withdrawn', $request);
    }

    public function deleteDraft(User $user, PublicFeedbackQuestionSet $set, ?Request $request = null): void
    {
        $this->assertDraft($set);

        DB::transaction(function () use ($user, $set, $request): void {
            $this->auditChange($user, $set, 'draft deleted', $request);
            $set->delete();
        });
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function writeQuestions(PublicFeedbackQuestionSet $set, array $questions): void
    {
        foreach (array_values($questions) as $index => $question) {
            $type = PublicFeedbackQuestionType::from((string) $question['question_type']);

            /** @var PublicFeedbackQuestion $row */
            $row = $set->questions()->create([
                'code' => (string) $question['code'],
                'section' => (string) $question['section'],
                'question_type' => $type->value,
                'label_en' => trim((string) $question['label_en']),
                'label_am' => $this->trimmedOrNull($question['label_am'] ?? null),
                'help_en' => $this->trimmedOrNull($question['help_en'] ?? null),
                'help_am' => $this->trimmedOrNull($question['help_am'] ?? null),
                'is_required' => (bool) ($question['is_required'] ?? false),
                'submission_types' => array_values(array_unique((array) $question['submission_types'])),
                'sort_order' => ($index + 1) * 10,
            ]);

            if (! $type->hasOptions()) {
                continue;
            }

            foreach (array_values((array) ($question['options'] ?? [])) as $optionIndex => $option) {
                $row->options()->create([
                    'code' => (string) $option['code'],
                    'label_en' => trim((string) $option['label_en']),
                    'label_am' => $this->trimmedOrNull($option['label_am'] ?? null),
                    'sort_order' => ($optionIndex + 1) * 10,
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function questionData(PublicFeedbackQuestion $question): array
    {
        return [
            'code' => $question->code,
            'section' => $question->section->value,
            'question_type' => $question->question_type->value,
            'label_en' => $question->label_en,
            'label_am' => $question->label_am,
            'help_en' => $question->help_en,
            'help_am' => $question->help_am,
            'is_required' => $question->is_required,
            'submission_types' => $question->submission_types,
            'options' => $question->options->map(fn ($option): array => [
                'code' => $option->code,
                'label_en' => $option->label_en,
                'label_am' => $option->label_am,
            ])->all(),
        ];
    }

    private function assertDraft(PublicFeedbackQuestionSet $set): void
    {
        if (! $set->isDraft()) {
            throw ValidationException::withMessages(['questionnaire' => __('service-feedback.questionnaire_not_draft')]);
        }
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function assertUniqueCodes(array $questions): void
    {
        $seen = [];

        foreach ($questions as $index => $question) {
            $code = (string) ($question['code'] ?? '');

            if (isset($seen[$code])) {
                throw ValidationException::withMessages(["questions.$index.code" => __('service-feedback.questionnaire_duplicate_code', ['code' => $code])]);
            }

            $seen[$code] = true;
        }
    }

    /** The public sees both languages: nothing is published with an English-only question or option. */
    private function assertPublishable(PublicFeedbackQuestionSet $set): void
    {
        if ($set->questions->isEmpty()) {
            throw ValidationException::withMessages(['questionnaire' => __('service-feedback.questionnaire_empty')]);
        }

        foreach ($set->questions as $question) {
            if (blank($question->label_am) || $question->options->contains(fn ($option): bool => blank($option->label_am))) {
                throw ValidationException::withMessages(['questionnaire' => __('service-feedback.questionnaire_missing_amharic', ['code' => $question->code])]);
            }

            if ($question->question_type->hasOptions() && $question->options->count() < 2) {
                throw ValidationException::withMessages(['questionnaire' => __('service-feedback.questionnaire_missing_options', ['code' => $question->code])]);
            }
        }
    }

    private function auditChange(User $user, PublicFeedbackQuestionSet $set, string $action, ?Request $request): void
    {
        $this->audit->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $user,
            auditable: $set,
            reason: 'Public feedback questionnaire '.$set->code.' v'.$set->version_no.' '.$action,
            request: $request,
        );
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
