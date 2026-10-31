<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\PerformanceCriterion;
use Modules\Court\Domain\Models\PerformanceEvaluation;
use Modules\Court\Domain\Models\PerformanceEvaluationCategory;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Database\Seeders\Court\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

// The migrations seed six active criteria (weights 20, 20, 15, 15, 15, 15 = 100)
// and four categories (efficiency, quality, conduct, general).

function perfPagesAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $admin->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $admin;
}

/** A user holding only the given permissions. */
function perfPagesUser(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'performance-pages-'.uniqid()]);
    $user->roles()->syncWithoutDetaching([$role->id]);
    foreach ($permissions as $permission) {
        DB::table('court_permission_role')->insert([
            'permission_id' => DB::table('court_permissions')->where('name', $permission)->value('id'),
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user;
}

/** What the React evaluation form (Court/Performance/Form) sends: every active criterion scored, and the button's action. */
function perfPagesPayload(User $member, string $action, int $score, string $notes = 'Steady quarter.'): array
{
    return [
        'evaluated_user_id' => (string) $member->id,
        'period_type' => 'quarterly',
        'period_start' => '2026-07-01',
        'period_end' => '2026-09-30',
        'notes' => $notes,
        'scores' => PerformanceCriterion::active()->get()
            ->map(fn (PerformanceCriterion $criterion): array => ['criterion_id' => $criterion->id, 'score' => $score, 'comment' => ''])
            ->values()->all(),
        'action' => $action,
    ];
}

test('the register lists each evaluation with its score, the totals, the status tabs and the permissions', function (): void {
    $admin = perfPagesAdmin();
    $member = User::factory()->create(['status' => 'active', 'name' => 'Selam Tesfaye']);
    PerformanceEvaluation::query()->create([
        'evaluated_user_id' => $member->id, 'evaluator_id' => $admin->id, 'period_type' => 'annual',
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'overall_score' => 87.5, 'status' => 'reviewed',
    ]);
    PerformanceEvaluation::query()->create([
        'evaluated_user_id' => $member->id, 'evaluator_id' => $admin->id, 'period_type' => 'monthly',
        'period_start' => '2025-06-01', 'period_end' => '2025-06-30', 'overall_score' => 40, 'status' => 'draft',
    ]);

    $this->actingAs($admin)->get(route('court.performance-evaluations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Index')
            ->has('evaluations.data', 2)
            ->where('evaluations.data.0.member_name', 'Selam Tesfaye')
            ->where('evaluations.data.0.period_type', 'annual')
            ->where('evaluations.data.0.period_start', '2026-01-01')
            ->where('evaluations.data.0.period_end', '2026-12-31')
            ->where('evaluations.data.0.overall_score', 87.5)
            ->where('evaluations.data.0.status', 'reviewed')
            ->where('evaluations.data.0.evaluator_name', $admin->name)
            ->where('evaluations.data.1.status', 'draft')
            ->where('evaluations.total', 2)
            ->where('stats', ['total' => 2, 'draft' => 1, 'submitted' => 0, 'reviewed' => 1, 'avg_score' => 87.5])
            ->where('filters', ['status' => '', 'user_id' => '', 'period_type' => ''])
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($member->id))
            ->has('categories', 4)
            ->where('categories.0', ['slug' => 'efficiency', 'name' => 'Efficiency'])
            ->where('can', ['create' => true, 'update' => true, 'manageSettings' => true])
            ->where('returnUrl', route('court.performance-evaluations.index')));

    $this->actingAs($admin)->get(route('court.performance-evaluations.index', ['status' => 'draft', 'user_id' => $member->id, 'period_type' => 'monthly']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('evaluations.data', 1)
            ->where('evaluations.data.0.status', 'draft')
            ->where('filters', ['status' => 'draft', 'user_id' => (string) $member->id, 'period_type' => 'monthly']));
});

test('a new evaluation is scored on the form, submitted, shown with its breakdown and reviewed', function (): void {
    $admin = perfPagesAdmin();
    $member = User::factory()->create(['status' => 'active', 'name' => 'Abebe Kebede']);
    PerformanceCriterion::query()->where('name', 'Technical Knowledge')->update(['active' => false]);

    $this->actingAs($admin)->get(route('court.performance-evaluations.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Form')
            ->where('evaluation', null)
            ->has('criteria', 6)
            ->where('criteria.0.name', 'Case Management')
            ->where('criteria.0.category', 'efficiency')
            ->where('criteria.0.category_label', 'Efficiency')
            ->where('criteria.0.weight', 20)
            ->where('criteria.0.description', 'Timely handling and resolution of assigned cases.')
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($member->id))
            ->where('scores', [])
            ->where('can.delete', false));

    $this->actingAs($admin)->post(route('court.performance-evaluations.store'), perfPagesPayload($member, 'submitted', 8))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.performance-evaluations.index'));

    $evaluation = PerformanceEvaluation::query()->where('evaluated_user_id', $member->id)->firstOrFail();
    expect($evaluation->status)->toBe('submitted')
        ->and($evaluation->overall_score)->toBe(80.0)
        ->and($evaluation->scores()->count())->toBe(5);

    $this->actingAs($admin)->get(route('court.performance-evaluations.show', $evaluation))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Show')
            ->where('evaluation.id', $evaluation->id)
            ->where('evaluation.member_name', 'Abebe Kebede')
            ->where('evaluation.period_type', 'quarterly')
            ->where('evaluation.period_start', '2026-07-01')
            ->where('evaluation.status', 'submitted')
            // JSON drops a whole float's ".0": the page receives 80.
            ->where('evaluation.overall_score', 80)
            ->where('evaluation.evaluator_name', $admin->name)
            ->where('evaluation.notes', 'Steady quarter.')
            ->where('evaluation.reviewer_name', null)
            ->has('scores', 5)
            ->where('scores.0.criterion_name', 'Case Management')
            ->where('scores.0.category_label', 'Efficiency')
            ->where('scores.0.weight', 20)
            ->where('scores.0.score', 8)
            ->where('scores.4.criterion_name', 'Teamwork & Collaboration')
            ->where('can', ['update' => true, 'review' => true]));

    $this->actingAs($admin)->post(route('court.performance-evaluations.review', $evaluation), ['reviewer_notes' => 'Approved as scored.'])
        ->assertRedirect(route('court.performance-evaluations.show', $evaluation));

    $this->actingAs($admin)->get(route('court.performance-evaluations.show', $evaluation))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('evaluation.status', 'reviewed')
            ->where('evaluation.reviewer_notes', 'Approved as scored.')
            ->where('evaluation.reviewer_name', $admin->name)
            ->where('evaluation.reviewed_at', fn ($value) => is_string($value) && $value !== ''));

    // A reviewed evaluation is locked.
    $this->actingAs($admin)->get(route('court.performance-evaluations.edit', $evaluation))->assertForbidden();
});

test('a draft opens on the form with its saved scores, is updated from the page payload and deleted', function (): void {
    $admin = perfPagesAdmin();
    $member = User::factory()->create(['status' => 'active', 'name' => 'Hana Girma']);

    $this->actingAs($admin)->post(route('court.performance-evaluations.store'), perfPagesPayload($member, 'draft', 5))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.performance-evaluations.index'));
    $evaluation = PerformanceEvaluation::query()->where('evaluated_user_id', $member->id)->firstOrFail();
    expect($evaluation->status)->toBe('draft')->and($evaluation->overall_score)->toBe(50.0);
    $first = PerformanceCriterion::active()->first();

    $this->actingAs($admin)->get(route('court.performance-evaluations.edit', $evaluation))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Form')
            ->where('evaluation', [
                'id' => $evaluation->id,
                'evaluated_user_id' => $member->id,
                'member_name' => 'Hana Girma',
                'period_type' => 'quarterly',
                'period_start' => '2026-07-01',
                'period_end' => '2026-09-30',
                'notes' => 'Steady quarter.',
                'status' => 'draft',
            ])
            ->has('criteria', 7)
            ->has('scores', 6)
            ->where('scores', fn ($scores) => collect($scores)->firstWhere('criterion_id', $first->id) === ['criterion_id' => $first->id, 'score' => 5, 'comment' => null])
            ->where('can.delete', true));

    $payload = perfPagesPayload($member, 'submitted', 10, 'Revised after discussion.');
    $payload['scores'][0]['comment'] = 'Cleared the backlog.';
    $this->actingAs($admin)->patch(route('court.performance-evaluations.update', $evaluation), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.performance-evaluations.show', $evaluation));

    $evaluation->refresh();
    expect($evaluation->status)->toBe('submitted')
        ->and($evaluation->overall_score)->toBe(100.0)
        ->and($evaluation->notes)->toBe('Revised after discussion.')
        ->and($evaluation->scores()->where('criterion_id', $first->id)->value('comment'))->toBe('Cleared the backlog.');

    // A bad payload comes back as field errors the form shows next to each field.
    $bad = perfPagesPayload($member, 'draft', 11);
    $bad['period_end'] = '2026-06-01';
    $this->actingAs($admin)->from(route('court.performance-evaluations.edit', $evaluation))
        ->patch(route('court.performance-evaluations.update', $evaluation), $bad)
        ->assertSessionHasErrors(['period_end', 'scores.0.score'])
        ->assertRedirect(route('court.performance-evaluations.edit', $evaluation));

    $this->actingAs($admin)->delete(route('court.performance-evaluations.destroy', $evaluation))
        ->assertRedirect(route('court.performance-evaluations.index'));
    $this->assertDatabaseMissing('court_performance_evaluations', ['id' => $evaluation->id]);
});

test('without the permissions the pages hide what the person cannot do', function (): void {
    $this->seed(PermissionSeeder::class);
    $viewer = perfPagesUser(['performance-evaluations.view']);
    $admin = perfPagesAdmin();
    $member = User::factory()->create(['status' => 'active']);
    $this->actingAs($admin)->post(route('court.performance-evaluations.store'), perfPagesPayload($member, 'submitted', 7))->assertSessionHasNoErrors();
    $evaluation = PerformanceEvaluation::query()->firstOrFail();

    $this->actingAs($viewer)->get(route('court.performance-evaluations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Performance/Index')
            ->where('can', ['create' => false, 'update' => false, 'manageSettings' => false]));
    $this->actingAs($viewer)->get(route('court.performance-evaluations.show', $evaluation))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can', ['update' => false, 'review' => false]));
    $this->actingAs($viewer)->post(route('court.performance-evaluations.review', $evaluation), [])
        ->assertRedirect(route('court.dashboard'));
    $this->actingAs($viewer)->get(route('court.settings.performance-evaluation.index'))
        ->assertRedirect(route('court.dashboard'));

    $reviewer = perfPagesUser(['performance-evaluations.view', 'performance-evaluations.review', 'performance-evaluations.update']);
    $this->actingAs($reviewer)->get(route('court.performance-evaluations.show', $evaluation))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can', ['update' => true, 'review' => true]));
    $this->actingAs($reviewer)->get(route('court.performance-evaluations.edit', $evaluation))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Performance/Form')->where('can.delete', false));
});

test('the settings page lists every criterion with its category, weight, state and usage', function (): void {
    $admin = perfPagesAdmin();
    $member = User::factory()->create(['status' => 'active']);
    $this->actingAs($admin)->post(route('court.performance-evaluations.store'), perfPagesPayload($member, 'draft', 6))->assertSessionHasNoErrors();
    PerformanceCriterion::query()->where('name', 'Technical Knowledge')->update(['active' => false]);

    $this->actingAs($admin)->get(route('court.settings.performance-evaluation.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/PerformanceSettings/Index')
            ->has('criteria', 7)
            ->where('criteria.0', [
                'id' => (int) PerformanceCriterion::query()->where('name', 'Case Management')->value('id'),
                'name' => 'Case Management',
                'name_am' => 'የጉዳይ አስተዳደር',
                'category' => 'efficiency',
                'category_label' => 'Efficiency',
                'weight' => 20,
                'sort_order' => 1,
                'active' => true,
                'description' => 'Timely handling and resolution of assigned cases.',
                'scores_count' => 1,
            ])
            ->where('criteria.5.name', 'Technical Knowledge')
            ->where('criteria.5.active', false)
            // With the criterion worked out from appeal outcomes (EUISIS ADR 0013 #14, weight 15).
            ->where('totalActiveWeight', 100)
            ->has('categories', 4)
            ->where('categories.3', ['slug' => 'general', 'name' => 'General'])
            ->where('returnUrl', route('court.settings.performance-evaluation.index')));
});

test('criteria and categories are added from the dialogs and pages, edited and deleted', function (): void {
    $admin = perfPagesAdmin();

    $this->actingAs($admin)->get(route('court.settings.performance-evaluation.criteria.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/PerformanceSettings/CreateCriterion')
            ->has('categories', 4)
            ->where('categories.0', ['slug' => 'efficiency', 'name' => 'Efficiency']));
    $this->actingAs($admin)->get(route('court.settings.performance-evaluation.categories.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/PerformanceSettings/CreateCategory'));

    // The Add Category dialog on the evaluations register comes back to the register.
    $this->actingAs($admin)->post(route('court.settings.performance-evaluation.categories.store'), [
        'name' => 'Innovation', 'name_am' => 'ፈጠራ', 'slug' => '', 'sort_order' => '5', 'active' => true,
        'redirect_to' => route('court.performance-evaluations.index'),
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.performance-evaluations.index'));
    $category = PerformanceEvaluationCategory::query()->where('slug', 'innovation')->firstOrFail();
    expect($category->name_am)->toBe('ፈጠራ')->and($category->sort_order)->toBe(5)->and($category->active)->toBeTrue();

    // A taken slug is a field error.
    $this->actingAs($admin)->from(route('court.settings.performance-evaluation.categories.create'))
        ->post(route('court.settings.performance-evaluation.categories.store'), ['name' => 'Speed', 'slug' => 'efficiency', 'sort_order' => '0', 'active' => true, 'redirect_to' => ''])
        ->assertSessionHasErrors('slug');

    // The Add Criterion page (no redirect_to) comes back to the settings page; an unticked Active is kept.
    $this->actingAs($admin)->post(route('court.settings.performance-evaluation.criteria.store'), [
        'name' => 'Initiative', 'name_am' => 'ተነሳሽነት', 'category' => 'innovation', 'weight' => '10',
        'sort_order' => '7', 'active' => false, 'description' => 'Takes initiative.', 'redirect_to' => '',
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.settings.performance-evaluation.index'));
    $criterion = PerformanceCriterion::query()->where('name', 'Initiative')->firstOrFail();
    expect($criterion->active)->toBeFalse()->and($criterion->weight)->toBe(10)->and($criterion->category)->toBe('innovation');

    $this->actingAs($admin)->get(route('court.settings.performance-evaluation.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('criteria', 8)
            ->where('criteria', fn ($criteria): bool => ($row = collect($criteria)->firstWhere('name', 'Initiative')) !== null
                && $row['category_label'] === 'Innovation' && $row['scores_count'] === 0));

    // The Edit Criterion dialog.
    $this->actingAs($admin)->patch(route('court.settings.performance-evaluation.criteria.update', $criterion), [
        'name' => 'Initiative', 'name_am' => 'ተነሳሽነት', 'category' => 'general', 'weight' => '5',
        'sort_order' => '8', 'active' => true, 'description' => '', 'redirect_to' => route('court.settings.performance-evaluation.index'),
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.settings.performance-evaluation.index'));
    $criterion->refresh();
    expect($criterion->active)->toBeTrue()->and($criterion->weight)->toBe(5)->and($criterion->category)->toBe('general')->and($criterion->description)->toBeNull();

    // An unused criterion is deleted; a scored one is deactivated instead.
    $this->actingAs($admin)->delete(route('court.settings.performance-evaluation.criteria.destroy', $criterion))
        ->assertRedirect(route('court.settings.performance-evaluation.index'));
    $this->assertDatabaseMissing('court_performance_evaluation_criteria', ['id' => $criterion->id]);

    $member = User::factory()->create(['status' => 'active']);
    $this->actingAs($admin)->post(route('court.performance-evaluations.store'), perfPagesPayload($member, 'draft', 6))->assertSessionHasNoErrors();
    $used = PerformanceCriterion::query()->where('name', 'Case Management')->firstOrFail();
    $this->actingAs($admin)->delete(route('court.settings.performance-evaluation.criteria.destroy', $used))
        ->assertRedirect(route('court.settings.performance-evaluation.index'))
        ->assertSessionHas('success', __('court::performance.settings.messages.deactivated_in_use'));
    expect($used->refresh()->active)->toBeFalse();
});
