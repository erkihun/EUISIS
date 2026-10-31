<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\DecisionReview;
use Modules\Court\Domain\Models\DecisionTemplate;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function decisionFormsUser(array $permissions, string $name = 'Decision Judge'): User
{
    $user = User::factory()->create(['name' => $name, 'status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Decision forms '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $permission): int => Permission::query()->firstOrCreate(['name' => $permission], ['label' => Str::headline($permission)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function decisionFormsCase(string $status = 'closed'): CourtCase
{
    $type = CaseType::query()->create(['name' => 'Decision forms '.Str::random(4)]);

    return CourtCase::query()->create([
        'case_number' => 'DF-'.Str::upper(Str::random(8)),
        'title' => 'Abebe Kebede',
        'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(),
        'status' => $status,
        'respondent_name' => 'Addis Ababa City Administration',
    ]);
}

/** A draft decision whose middle seat (the author) is the given judge. */
function decisionFormsDecision(CourtCase $case, User $middleJudge, array $attributes = []): Decision
{
    return Decision::query()->create(array_merge([
        'court_case_id' => $case->id,
        'case_number' => $case->case_number,
        'case_file_number' => 'FILE-'.Str::upper(Str::random(4)),
        'applicant_full_name' => $case->title,
        'respondent_full_name' => $case->respondent_name,
        'decision_date' => now()->toDateString(),
        'panel_judges' => [
            ['order' => 1, 'admin_user_id' => null, 'admin_user_name' => null, 'vote' => null],
            ['order' => 2, 'admin_user_id' => $middleJudge->id, 'admin_user_name' => $middleJudge->name, 'vote' => null],
            ['order' => 3, 'admin_user_id' => null, 'admin_user_name' => null, 'vote' => null],
        ],
        'reviewing_admin_user_id' => $middleJudge->id,
        'reviewing_admin_user_name' => $middleJudge->name,
        'name' => 'Final ruling',
        'decision_content' => '<p>The appeal is upheld.</p>',
        'status' => 'draft',
    ], $attributes));
}

test('the create page offers the closed cases, the judges and the signed-in judge in the middle seat', function (): void {
    $judge = decisionFormsUser(['decision.view', 'decision.view.all', 'decision.create']);
    $closed = decisionFormsCase('closed');
    $active = decisionFormsCase('active');

    $this->actingAs($judge)->get(route('court.decisions.create', ['case_id' => $closed->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/Create')
            ->where('decision', null)
            ->where('cases', fn ($cases) => collect($cases)->pluck('id')->contains($closed->id) && ! collect($cases)->pluck('id')->contains($active->id))
            ->where('cases', fn ($cases) => collect($cases)->firstWhere('id', $closed->id)['applicant'] === 'Abebe Kebede'
                && collect($cases)->firstWhere('id', $closed->id)['respondent'] === 'Addis Ababa City Administration')
            ->where('judgeUsers', fn ($judges) => collect($judges)->pluck('id')->contains($judge->id))
            ->where('currentUser.id', $judge->id)
            ->where('defaults.case_id', $closed->id)
            ->where('defaults.decision_date', now()->toDateString())
            ->where('defaults.judges', [null, $judge->id, null]));
});

test('a decision is still stored from the form payload', function (): void {
    $judge = decisionFormsUser(['decision.view', 'decision.view.all', 'decision.create']);
    $other = User::factory()->create(['status' => 'active']);
    // A decision needs a complete panel of three different judges.
    $third = User::factory()->create(['status' => 'active']);
    $case = decisionFormsCase('closed');

    $this->actingAs($judge)
        ->from(route('court.decisions.create'))
        ->post(route('court.decisions.store'), [
            'case_id' => $case->id,
            'case_file_number' => 'FILE-77',
            'decision_date' => now()->toDateString(),
            'decision_content' => '<p>The appeal is upheld.</p>',
            'judges' => [
                ['admin_user_id' => $other->id],
                ['admin_user_id' => $judge->id],
                ['admin_user_id' => $third->id],
            ],
        ])
        ->assertRedirect(route('court.decisions.index'))
        ->assertSessionHasNoErrors();

    $decision = Decision::query()->firstWhere('court_case_id', $case->id);
    expect($decision)->not->toBeNull()
        ->and($decision->status)->toBe('draft')
        ->and($decision->case_file_number)->toBe('FILE-77')
        ->and($decision->applicant_full_name)->toBe('Abebe Kebede')
        ->and($decision->panel_judges[1]['admin_user_id'])->toBe($judge->id)
        ->and($decision->panel_judges[0]['admin_user_name'])->toBe($other->name);
});

test('the show page carries the decision, its panel, the reviews, the templates and the actions', function (): void {
    $judge = decisionFormsUser(['decision.view', 'decision.view.all', 'decision.update', 'decision.approve']);
    $colleague = User::factory()->create(['name' => 'Second Reviewer', 'status' => 'active']);
    $case = decisionFormsCase('closed');
    // Publishing and approving need a complete panel: three different judges, the author presiding.
    [$first, $third] = User::factory()->count(2)->create(['status' => 'active'])->all();
    $decision = decisionFormsDecision($case, $judge, ['panel_judges' => [
        ['order' => 1, 'admin_user_id' => $first->id, 'admin_user_name' => $first->name, 'vote' => null],
        ['order' => 2, 'admin_user_id' => $judge->id, 'admin_user_name' => $judge->name, 'vote' => null],
        ['order' => 3, 'admin_user_id' => $third->id, 'admin_user_name' => $third->name, 'vote' => null],
    ]]);
    $template =DecisionTemplate::query()->create(['title' => 'Standard layout', 'body' => '<p>{{decision_content}}</p>', 'is_default' => true]);
    $own = DecisionReview::query()->create(['decision_id' => $decision->id, 'case_number' => $decision->case_number, 'reviewer_id' => $judge->id, 'outcome' => 'improve', 'review_note' => 'Tighten the reasoning.']);
    DecisionReview::query()->create(['decision_id' => $decision->id, 'case_number' => $decision->case_number, 'reviewer_id' => $colleague->id, 'outcome' => 'approve']);

    $this->actingAs($judge)->get(route('court.decisions.show', $decision))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/Show')
            ->where('decision.id', $decision->id)
            ->where('decision.name', 'Final ruling')
            ->where('decision.case_id', $case->id)
            ->where('decision.case_number', $case->case_number)
            ->where('decision.status', 'draft')
            ->where('decision.approved', false)
            ->where('decision.decision_date', now()->toDateString())
            ->where('decision.decision_content', '<p>The appeal is upheld.</p>')
            ->has('decision.panel', 3, fn (AssertableInertia $seat) => $seat->where('number', 1)->where('name', $judge->name)->where('vote', null))
            ->has('reviews', 2)
            ->where('reviews', fn ($reviews) => collect($reviews)->firstWhere('id', $own->id)['is_owner'] === true
                && collect($reviews)->firstWhere('id', $own->id)['review_note'] === 'Tighten the reasoning.'
                && collect($reviews)->firstWhere('outcome', 'approve')['is_owner'] === false)
            ->where('templates', [['id' => $template->id, 'title' => 'Standard layout']])
            ->where('statuses', ['draft', 'published'])
            ->where('can.edit', true)
            ->where('can.changeStatus', true)
            ->where('can.approve', true)
            ->where('can.review', true));

    // The reviewer's own review opens on its edit page.
    $this->actingAs($judge)->get(route('court.decisions.reviews.edit', [$decision, $own]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/EditReview')
            ->where('decision.id', $decision->id)
            ->where('decision.case_number', $case->case_number)
            ->where('review.id', $own->id)
            ->where('review.outcome', 'improve')
            ->where('review.review_note', 'Tighten the reasoning.')
            ->where('review.reviewer_name', $judge->name));

    // Publishing from the page locks editing and reviewing; approval then follows.
    $this->actingAs($judge)->from(route('court.decisions.show', $decision))
        ->patch(route('court.decisions.status', $decision), ['status' => 'published'])
        ->assertRedirect(route('court.decisions.show', $decision));
    $this->actingAs($judge)->patch(route('court.decisions.approve', $decision))->assertRedirect(route('court.decisions.show', $decision));

    $this->actingAs($judge)->get(route('court.decisions.show', $decision))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('decision.status', 'published')
            ->where('decision.approved', true)
            ->where('decision.approver_name', $judge->name)
            ->where('can.edit', false)
            ->etc());
});

test('the edit page seeds the form for the middle judge and refuses anyone else', function (): void {
    $judge = decisionFormsUser(['decision.view', 'decision.view.all', 'decision.update']);
    $other = decisionFormsUser(['decision.view', 'decision.view.all', 'decision.update'], 'Other Judge');
    $case = decisionFormsCase('closed');
    $decision = decisionFormsDecision($case, $judge);

    $this->actingAs($judge)->get(route('court.decisions.edit', $decision))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/Edit')
            ->where('decision.id', $decision->id)
            ->where('decision.case_id', $case->id)
            ->where('decision.case_file_number', $decision->case_file_number)
            ->where('decision.applicant_full_name', 'Abebe Kebede')
            ->where('decision.decision_content', '<p>The appeal is upheld.</p>')
            ->where('decision.judges', [null, $judge->id, null])
            ->where('cases', fn ($cases) => collect($cases)->pluck('id')->contains($case->id))
            ->where('currentUser.id', $judge->id)
            ->etc());

    $this->actingAs($other)->get(route('court.decisions.edit', $decision))->assertForbidden();
});

test('the decision templates register, create and edit pages render and the store still works', function (): void {
    $admin = decisionFormsUser(['decision.templet.view', 'decision.templet.create', 'decision.templet.update', 'decision.templet.delete'], 'Registrar');
    $template = DecisionTemplate::query()->create([
        'title' => 'Final judgment',
        'category' => 'Judgments',
        'body' => '<p>{{applicant_name}} v {{respondent_name}}</p>',
        'placeholders' => ['applicant_name', 'respondent_name'],
        'is_default' => true,
        'header_image_path' => 'decision-templates/headers/seal.png',
    ]);

    $this->actingAs($admin)->get(route('court.decision-templates.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/DecisionTemplates/Index')
            ->where('templates.total', 1)
            ->where('templates.data.0.id', $template->id)
            ->where('templates.data.0.title', 'Final judgment')
            ->where('templates.data.0.category', 'Judgments')
            ->where('templates.data.0.is_default', true)
            ->where('templates.data.0.placeholders', 'applicant_name, respondent_name')
            ->where('can', ['create' => true, 'update' => true, 'delete' => true]));

    $this->actingAs($admin)->get(route('court.decision-templates.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/DecisionTemplates/Create')->where('template', null));

    $this->actingAs($admin)->get(route('court.decision-templates.edit', $template))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/DecisionTemplates/Edit')
            ->where('template.id', $template->id)
            ->where('template.title', 'Final judgment')
            ->where('template.category', 'Judgments')
            ->where('template.is_default', true)
            ->where('template.placeholders', 'applicant_name, respondent_name')
            ->where('template.body', '<p>{{applicant_name}} v {{respondent_name}}</p>')
            ->where('template.header_image_name', 'seal.png')
            ->where('template.header_image_url', asset('storage/decision-templates/headers/seal.png'))
            ->where('template.footer_image_name', null)
            ->where('template.footer_image_url', null));

    $this->actingAs($admin)
        ->post(route('court.decision-templates.store'), ['title' => 'Ruling', 'category' => '', 'is_default' => 0, 'placeholders' => "case_number\njudge_name", 'body' => '<p>{{case_number}}</p>'])
        ->assertRedirect(route('court.decision-templates.index'))
        ->assertSessionHasNoErrors();
    $stored = DecisionTemplate::query()->firstWhere('title', 'Ruling');
    expect($stored)->not->toBeNull()
        ->and($stored->placeholders)->toBe(['case_number', 'judge_name'])
        ->and($stored->is_default)->toBeFalse();

    // The method-spoofed multipart update the React form sends.
    $this->actingAs($admin)
        ->post(route('court.decision-templates.update', $template), ['_method' => 'PATCH', 'title' => 'Final judgment (v2)', 'is_default' => 1, 'placeholders' => '', 'body' => '<p>v2</p>', 'remove_header_image' => 0, 'remove_footer_image' => 0])
        ->assertRedirect(route('court.decision-templates.index'))
        ->assertSessionHasNoErrors();
    expect($template->fresh()->title)->toBe('Final judgment (v2)');
});
