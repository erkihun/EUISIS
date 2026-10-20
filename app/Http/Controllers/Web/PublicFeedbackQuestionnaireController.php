<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PublicFeedbackQuestionSetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePublicFeedbackQuestionnaireRequest;
use App\Models\EmployeeServiceFeedback;
use App\Models\PublicFeedbackQuestion;
use App\Models\PublicFeedbackQuestionSet;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaireAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization feedback questionnaire, administered under the public
 * feedback settings (docs/organization-public-feedback-form.md §4): its
 * versions, the one draft, publishing and withdrawing. Never on a public page.
 */
class PublicFeedbackQuestionnaireController extends Controller
{
    public function __construct(private readonly PublicFeedbackQuestionnaireAdmin $admin) {}

    public function index(): Response
    {
        $this->authorizeManage();

        $sets = PublicFeedbackQuestionSet::query()
            ->where('code', PublicFeedbackQuestionnaire::ORGANIZATION_FORM)
            ->withCount('questions')
            ->orderByDesc('version_no')
            ->get();
        $answered = EmployeeServiceFeedback::query()
            ->whereIn('question_set_id', $sets->modelKeys())
            ->selectRaw('question_set_id, COUNT(*) AS total')
            ->groupBy('question_set_id')
            ->pluck('total', 'question_set_id');

        return Inertia::render('ServiceFeedback/Questionnaire/Index', [
            'versions' => $sets->map(fn (PublicFeedbackQuestionSet $set): array => [
                'id' => $set->id,
                'version_no' => $set->version_no,
                'status' => $set->status->value,
                'name_en' => $set->name_en,
                'name_am' => $set->name_am,
                'question_count' => (int) $set->questions_count,
                'submission_count' => (int) ($answered[$set->id] ?? 0),
                'published_at' => $set->published_at?->toIso8601String(),
                'retired_at' => $set->retired_at?->toIso8601String(),
                'updated_at' => $set->updated_at?->toIso8601String(),
            ])->values()->all(),
            'hasDraft' => $sets->contains(fn (PublicFeedbackQuestionSet $set): bool => $set->status === PublicFeedbackQuestionSetStatus::Draft),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $draft = $this->admin->createDraft($this->user(), $request);

        return redirect()->route('service-feedback.questionnaire.edit', $draft)->with('success', __('service-feedback.questionnaire_draft_created'));
    }

    /** A draft opens for editing; a published or retired version opens read-only. */
    public function edit(PublicFeedbackQuestionSet $questionSet): Response
    {
        $this->authorizeManage();
        $this->assertOrganizationForm($questionSet);
        $questionSet->load('questions.options');

        return Inertia::render('ServiceFeedback/Questionnaire/Edit', [
            'questionSet' => [
                'id' => $questionSet->id,
                'version_no' => $questionSet->version_no,
                'status' => $questionSet->status->value,
                'name_en' => $questionSet->name_en,
                'name_am' => $questionSet->name_am ?? '',
                'published_at' => $questionSet->published_at?->toIso8601String(),
                'questions' => $questionSet->questions->map(fn (PublicFeedbackQuestion $question): array => [
                    'code' => $question->code,
                    'section' => $question->section->value,
                    'question_type' => $question->question_type->value,
                    'label_en' => $question->label_en,
                    'label_am' => $question->label_am ?? '',
                    'help_en' => $question->help_en ?? '',
                    'help_am' => $question->help_am ?? '',
                    'is_required' => $question->is_required,
                    'submission_types' => $question->submission_types,
                    'options' => $question->options->map(fn ($option): array => [
                        'code' => $option->code,
                        'label_en' => $option->label_en,
                        'label_am' => $option->label_am ?? '',
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'editable' => $questionSet->isDraft(),
        ]);
    }

    public function update(SavePublicFeedbackQuestionnaireRequest $request, PublicFeedbackQuestionSet $questionSet): RedirectResponse
    {
        $this->assertOrganizationForm($questionSet);
        $this->admin->saveDraft($this->user(), $questionSet, $request->validated(), $request);

        return redirect()->route('service-feedback.questionnaire.edit', $questionSet)->with('success', __('service-feedback.questionnaire_saved'));
    }

    public function publish(Request $request, PublicFeedbackQuestionSet $questionSet): RedirectResponse
    {
        $this->authorizeManage();
        $this->assertOrganizationForm($questionSet);
        $this->admin->publish($this->user(), $questionSet, $request);

        return redirect()->route('service-feedback.questionnaire.index')->with('success', __('service-feedback.questionnaire_published'));
    }

    public function retire(Request $request, PublicFeedbackQuestionSet $questionSet): RedirectResponse
    {
        $this->authorizeManage();
        $this->assertOrganizationForm($questionSet);
        $this->admin->retire($this->user(), $questionSet, $request);

        return redirect()->route('service-feedback.questionnaire.index')->with('success', __('service-feedback.questionnaire_retired'));
    }

    public function destroy(Request $request, PublicFeedbackQuestionSet $questionSet): RedirectResponse
    {
        $this->authorizeManage();
        $this->assertOrganizationForm($questionSet);
        $this->admin->deleteDraft($this->user(), $questionSet, $request);

        return redirect()->route('service-feedback.questionnaire.index')->with('success', __('service-feedback.questionnaire_deleted'));
    }

    private function assertOrganizationForm(PublicFeedbackQuestionSet $questionSet): void
    {
        abort_unless($questionSet->code === PublicFeedbackQuestionnaire::ORGANIZATION_FORM, 404);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->admin->canManage($this->user()), 403);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
