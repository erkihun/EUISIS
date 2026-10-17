<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\PublicFeedbackQuestionSetStatus;
use App\Models\AuditLog;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackQuestionSet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaireAdmin;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaireTemplate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Administering the organization feedback questionnaire
 * (docs/organization-public-feedback-form.md §4): city-level only; one draft
 * at a time, started from the proposed template; nothing reaches the public
 * before it is published; a published version is frozen and a newer one
 * retires it; staff screens show the answers in the wording answered.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    $this->withHeader('X-Locale', 'en');

    foreach ([PublicFeedbackQuestionnaireAdmin::PERMISSION, 'service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.view_organization_target'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([PublicFeedbackQuestionnaireAdmin::PERMISSION]);

    $this->scenario = PublicFeedbackScenario::employeeTarget('QAD');
    $this->cityAdmin = User::factory()->create();
    $this->cityAdmin->assignRole('Super Admin');

    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $this->orgAdmin->id,
        'organization_id' => $this->scenario->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->token = app(OrganizationFeedbackQrService::class)->ensureActive($this->scenario->organization, $this->cityAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

/** The draft's questions in the shape the editor saves them. */
function qadQuestions(PublicFeedbackQuestionSet $set): array
{
    return $set->questions()->with('options')->get()->map(fn ($question) => [
        'code' => $question->code,
        'section' => $question->section->value,
        'question_type' => $question->question_type->value,
        'label_en' => $question->label_en,
        'label_am' => $question->label_am,
        'help_en' => $question->help_en,
        'help_am' => $question->help_am,
        'is_required' => $question->is_required,
        'submission_types' => $question->submission_types,
        'options' => $question->options->map(fn ($option) => ['code' => $option->code, 'label_en' => $option->label_en, 'label_am' => $option->label_am])->all(),
    ])->all();
}

it('is managed by city-level accounts with the permission only', function (): void {
    $this->actingAs(User::factory()->create())->get(route('service-feedback.questionnaire.index'))->assertForbidden();
    // The questionnaire is city-wide: an institution's own administrator cannot change it.
    $this->actingAs($this->orgAdmin)->get(route('service-feedback.questionnaire.index'))->assertForbidden();
    $this->actingAs($this->orgAdmin)->post(route('service-feedback.questionnaire.store'))->assertForbidden();

    $this->actingAs($this->cityAdmin)->get(route('service-feedback.questionnaire.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceFeedback/Questionnaire/Index')->where('versions', [])->where('hasDraft', false));
});

it('starts one draft from the proposed template, which the public does not see', function (): void {
    $this->actingAs($this->cityAdmin)->post(route('service-feedback.questionnaire.store'))->assertRedirect();
    $this->actingAs($this->cityAdmin)->post(route('service-feedback.questionnaire.store'))->assertRedirect();

    $draft = PublicFeedbackQuestionSet::query()->sole();

    expect($draft->status)->toBe(PublicFeedbackQuestionSetStatus::Draft)
        ->and($draft->version_no)->toBe(1)
        ->and($draft->questions()->pluck('code')->all())->toBe(array_column(PublicFeedbackQuestionnaireTemplate::questions(), 'code'))
        ->and(AuditLog::query()->where('auditable_id', $draft->id)->exists())->toBeTrue();

    $this->get('/feedback/'.$this->token.'/feedback')->assertInertia(fn (Assert $page) => $page->where('questionnaire', null));

    $this->actingAs($this->cityAdmin)->get(route('service-feedback.questionnaire.edit', $draft))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceFeedback/Questionnaire/Edit')->where('editable', true)->has('questionSet.questions', count(PublicFeedbackQuestionnaireTemplate::questions())));
});

it('saves a draft whole, with choices, and validates codes', function (): void {
    $draft = app(PublicFeedbackQuestionnaireAdmin::class)->createDraft($this->cityAdmin);

    $this->actingAs($this->cityAdmin)->put(route('service-feedback.questionnaire.update', $draft), [
        'name_en' => 'Service feedback',
        'name_am' => 'የአገልግሎት አስተያየት',
        'questions' => [[
            'code' => 'visit_reason', 'section' => 'details', 'question_type' => 'single_choice',
            'label_en' => 'Why did you come?', 'label_am' => 'ለምን መጡ?', 'is_required' => true, 'submission_types' => ['feedback'],
            'options' => [
                ['code' => 'new', 'label_en' => 'New application', 'label_am' => 'አዲስ ማመልከቻ'],
                ['code' => 'follow_up', 'label_en' => 'Follow-up', 'label_am' => 'ክትትል'],
            ],
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect(route('service-feedback.questionnaire.edit', $draft));

    $question = $draft->fresh()->questions()->sole();
    expect($question->code)->toBe('visit_reason')
        ->and($question->options()->pluck('code')->all())->toBe(['new', 'follow_up']);

    $this->actingAs($this->cityAdmin)->put(route('service-feedback.questionnaire.update', $draft), [
        'name_en' => 'Service feedback',
        'questions' => [
            ['code' => 'Bad Code', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'X', 'submission_types' => ['feedback']],
            ['code' => 'ok', 'section' => 'nowhere', 'question_type' => 'slider', 'label_en' => 'Y', 'submission_types' => ['petition']],
        ],
    ])->assertSessionHasErrors(['questions.0.code', 'questions.1.section', 'questions.1.question_type', 'questions.1.submission_types.0']);

    $this->actingAs($this->cityAdmin)->put(route('service-feedback.questionnaire.update', $draft), [
        'name_en' => 'Service feedback',
        'questions' => [
            ['code' => 'same', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'X', 'submission_types' => ['feedback']],
            ['code' => 'same', 'section' => 'experience', 'question_type' => 'rating', 'label_en' => 'Y', 'submission_types' => ['feedback']],
        ],
    ])->assertSessionHasErrors(['questions.1.code']);

    // A refused save leaves the saved draft as it was.
    expect($draft->fresh()->questions()->pluck('code')->all())->toBe(['visit_reason']);
});

it('publishes only a complete bilingual draft, retiring the version it replaces', function (): void {
    $admin = app(PublicFeedbackQuestionnaireAdmin::class);
    $draft = $admin->createDraft($this->cityAdmin);
    $questions = qadQuestions($draft);
    $questions[0]['label_am'] = '';
    $admin->saveDraft($this->cityAdmin, $draft, ['name_en' => 'Service feedback', 'questions' => $questions]);

    $this->actingAs($this->cityAdmin)->post(route('service-feedback.questionnaire.publish', $draft))->assertSessionHasErrors(['questionnaire']);
    expect($draft->fresh()->status)->toBe(PublicFeedbackQuestionSetStatus::Draft);

    $questions[0]['label_am'] = 'የተሰጠዎት መረጃ ግልጽነት';
    $admin->saveDraft($this->cityAdmin, $draft, ['name_en' => 'Service feedback', 'questions' => $questions]);
    $this->actingAs($this->cityAdmin)->post(route('service-feedback.questionnaire.publish', $draft))->assertSessionHasNoErrors();

    expect($draft->fresh()->status)->toBe(PublicFeedbackQuestionSetStatus::Published);
    $this->get('/feedback/'.$this->token.'/feedback')->assertInertia(fn (Assert $page) => $page->where('questionnaire.version', 1));

    // Version 2: a copy of version 1, published over it.
    $second = $admin->createDraft($this->cityAdmin);
    expect($second->version_no)->toBe(2)->and(qadQuestions($second))->toBe(qadQuestions($draft->fresh()));
    $admin->publish($this->cityAdmin, $second);

    expect($draft->fresh()->status)->toBe(PublicFeedbackQuestionSetStatus::Retired)
        ->and($draft->fresh()->retired_at)->not->toBeNull()
        ->and(PublicFeedbackQuestionSet::query()->where('status', 'published')->sole()->id)->toBe($second->id);
});

it('never edits or deletes a published version, and withdrawing it leaves the form without a questionnaire', function (): void {
    $admin = app(PublicFeedbackQuestionnaireAdmin::class);
    $set = $admin->createDraft($this->cityAdmin);
    $admin->publish($this->cityAdmin, $set);

    $this->actingAs($this->cityAdmin)->put(route('service-feedback.questionnaire.update', $set), ['name_en' => 'Changed', 'questions' => []])
        ->assertSessionHasErrors(['questionnaire']);
    $this->actingAs($this->cityAdmin)->delete(route('service-feedback.questionnaire.destroy', $set))->assertSessionHasErrors(['questionnaire']);
    $this->actingAs($this->cityAdmin)->get(route('service-feedback.questionnaire.edit', $set))->assertInertia(fn (Assert $page) => $page->where('editable', false));

    expect($set->fresh()->name_en)->toBe(PublicFeedbackQuestionnaireTemplate::name()['name_en']);

    $this->actingAs($this->cityAdmin)->post(route('service-feedback.questionnaire.retire', $set))->assertSessionHasNoErrors();
    expect($set->fresh()->status)->toBe(PublicFeedbackQuestionSetStatus::Retired);
    $this->get('/feedback/'.$this->token.'/feedback')->assertInertia(fn (Assert $page) => $page->where('questionnaire', null));
});

it('deletes a draft that was never published', function (): void {
    $draft = app(PublicFeedbackQuestionnaireAdmin::class)->createDraft($this->cityAdmin);

    $this->actingAs($this->cityAdmin)->delete(route('service-feedback.questionnaire.destroy', $draft))->assertRedirect(route('service-feedback.questionnaire.index'));

    expect(PublicFeedbackQuestionSet::query()->count())->toBe(0);
});

it('shows staff the service and the answers of an organization Complaint', function (): void {
    $admin = app(PublicFeedbackQuestionnaireAdmin::class);
    $set = $admin->createDraft($this->cityAdmin);
    $admin->publish($this->cityAdmin, $set);

    $this->post('/feedback/'.$this->token.'/complaint', pfComplete('complaint', [
        'questionnaire_version' => 1,
        'service_text' => 'Stamp renewal',
        'service_date' => now()->toDateString(),
        'service_time_band' => 'afternoon',
        'message' => 'Nobody was at the window.',
        'answers' => ['service_obtained' => 'no', 'desired_resolution' => 'Staff the window.'],
    ]))->assertSessionHasNoErrors();

    $complaint = EmployeeServiceFeedback::query()->sole();

    $this->actingAs($this->cityAdmin)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/SubmissionDetail')
            ->where('organizationForm.service', 'Stamp renewal')
            ->where('organizationForm.service_unstructured', true)
            ->where('organizationForm.service_time_band', 'afternoon')
            ->where('organizationForm.questionnaire_version', 1)
            ->where('organizationForm.answers.0.code', 'service_obtained')
            ->where('organizationForm.answers.0.value', 'no')
            ->where('organizationForm.answers.1.question', 'What outcome are you asking for?')
            ->where('organizationForm.answers.1.value', 'Staff the window.'));
});
