<?php

declare(strict_types=1);

/*
 * A decision is attributable only to a complete panel: three different
 * judges, with the presiding (middle) seat held by the judge who submits it,
 * on create and on update. Publishing and approving need that panel; a legacy
 * draft without a presiding judge can be opened by an authorised editor to
 * fix it; seats are read by index; the content is cleaned again on output and
 * the edit form keeps a custom case file number.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Inertia\Testing\AssertableInertia;

function decisionPanelEditor(): User
{
    return jwStaff(['decision.view', 'decision.view.all', 'decision.create', 'decision.update', 'decision.approve']);
}

/** @return array<int, array{order: int, admin_user_id: ?int, admin_user_name: ?string, vote: null}> */
function decisionPanelOf(?User $first, ?User $presiding, ?User $third): array
{
    return collect([$first, $presiding, $third])
        ->map(fn (?User $judge, int $index): array => [
            'order' => $index + 1,
            'admin_user_id' => $judge?->id,
            'admin_user_name' => $judge?->name,
            'vote' => null,
        ])
        ->all();
}

function decisionPanelDecision(CourtCase $case, array $panel, string $status = 'draft', array $attributes = []): Decision
{
    return Decision::query()->create(array_merge([
        'court_case_id' => $case->id,
        'case_number' => $case->case_number,
        'name' => 'Panel decision',
        'decision_content' => '<p>Judgment.</p>',
        'decision_date' => now()->toDateString(),
        'panel_judges' => $panel,
        'status' => $status,
    ], $attributes));
}

/** @return array<string, mixed> */
function decisionPanelPayload(CourtCase $case, array $judgeIds, array $extra = []): array
{
    return array_merge([
        'case_id' => $case->id,
        'decision_date' => now()->toDateString(),
        'decision_content' => '<p>Judgment for the applicant.</p>',
        'judges' => array_map(fn (?int $id): array => ['admin_user_id' => $id], $judgeIds),
    ], $extra);
}

test('a decision cannot be created without all three judges', function (): void {
    $presiding = decisionPanelEditor();
    $wing = User::factory()->create();
    $case = jwCase(['status' => 'closed']);

    $this->actingAs($presiding)
        ->post(route('court.decisions.store'), decisionPanelPayload($case, [$wing->id, $presiding->id, null]))
        ->assertSessionHasErrors(['judges.2.admin_user_id' => __('court::decisions.validation.panel_required')]);

    $payload = decisionPanelPayload($case, []);
    unset($payload['judges']);
    $this->actingAs($presiding)
        ->post(route('court.decisions.store'), $payload)
        ->assertSessionHasErrors(['judges' => __('court::decisions.validation.panel_required')]);

    expect(Decision::query()->count())->toBe(0);
});

test('the same judge cannot fill two seats', function (): void {
    $presiding = decisionPanelEditor();
    $wing = User::factory()->create();
    $case = jwCase(['status' => 'closed']);

    $this->actingAs($presiding)
        ->post(route('court.decisions.store'), decisionPanelPayload($case, [$wing->id, $presiding->id, $wing->id]))
        ->assertSessionHasErrors(['judges.0.admin_user_id' => __('court::decisions.validation.panel_distinct')]);

    expect(Decision::query()->count())->toBe(0);
});

test('the presiding seat must be held by the judge submitting the decision', function (): void {
    $submitter = decisionPanelEditor();
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    $case = jwCase(['status' => 'closed']);

    $this->actingAs($submitter)
        ->post(route('court.decisions.store'), decisionPanelPayload($case, [$a->id, $b->id, $c->id]))
        ->assertSessionHasErrors(['judges.1.admin_user_id' => __('court::decisions.validation.presiding_judge_must_be_you')]);

    expect(Decision::query()->count())->toBe(0);
});

test('a complete panel is stored seat by seat, with the submitter presiding', function (): void {
    $presiding = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $case = jwCase(['status' => 'closed']);

    // Seats keyed out of order still land in their own seat.
    $payload = decisionPanelPayload($case, []);
    $payload['judges'] = [2 => ['admin_user_id' => $third->id], 0 => ['admin_user_id' => $first->id], 1 => ['admin_user_id' => $presiding->id]];

    $this->actingAs($presiding)
        ->post(route('court.decisions.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.decisions.index'));

    $decision = Decision::query()->sole();
    expect($decision->hasCompletePanel())->toBeTrue()
        ->and($decision->presidingJudgeId())->toBe($presiding->id)
        ->and((int) $decision->panel_judges[0]['admin_user_id'])->toBe($first->id)
        ->and((int) $decision->panel_judges[2]['admin_user_id'])->toBe($third->id);
});

test('an update cannot empty the panel', function (): void {
    $presiding = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $case = jwCase(['status' => 'closed']);
    $decision = decisionPanelDecision($case, decisionPanelOf($first, $presiding, $third));

    $this->actingAs($presiding)
        ->patch(route('court.decisions.update', $decision), decisionPanelPayload($case, [null, $presiding->id, null]))
        ->assertSessionHasErrors(['judges.0.admin_user_id' => __('court::decisions.validation.panel_required')]);

    expect($decision->fresh()->hasCompletePanel())->toBeTrue();
});

test('a decision with an incomplete panel can be neither published nor approved', function (): void {
    $presiding = decisionPanelEditor();
    $draft = decisionPanelDecision(jwCase(['status' => 'closed']), decisionPanelOf(null, $presiding, null));

    $this->actingAs($presiding)
        ->patch(route('court.decisions.status', $draft), ['status' => 'published'])
        ->assertSessionHasErrors(['status' => __('court::decisions.validation.publish_requires_panel')]);
    expect($draft->fresh()->status)->toBe('draft');

    $published = decisionPanelDecision(jwCase(['status' => 'closed']), decisionPanelOf(null, null, null), 'published');
    $this->actingAs($presiding)
        ->patch(route('court.decisions.approve', $published))
        ->assertSessionHasErrors(['approve' => __('court::decisions.validation.publish_requires_panel')]);
    expect($published->fresh()->approved_at)->toBeNull();

    $this->actingAs($presiding)
        ->get(route('court.decisions.show', $draft))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decision.panel_complete', false)->etc());
});

test('a decision with a complete panel can be published', function (): void {
    $presiding = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $decision = decisionPanelDecision(jwCase(['status' => 'closed']), decisionPanelOf($first, $presiding, $third));

    $this->actingAs($presiding)
        ->patch(route('court.decisions.status', $decision), ['status' => 'published'])
        ->assertSessionHasNoErrors();

    expect($decision->fresh()->status)->toBe('published');
});

test('a legacy draft with no presiding judge can be repaired by an authorised editor', function (): void {
    $editor = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $case = jwCase(['status' => 'closed']);
    $decision = decisionPanelDecision($case, decisionPanelOf(null, null, null));

    $this->actingAs($editor)
        ->get(route('court.decisions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decisions.data.0.can_edit', true)->etc());

    $this->actingAs($editor)
        ->get(route('court.decisions.edit', $decision))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decision.judges', [null, $editor->id, null])->etc());

    $this->actingAs($editor)
        ->patch(route('court.decisions.update', $decision), decisionPanelPayload($case, [$first->id, $editor->id, $third->id]))
        ->assertSessionHasNoErrors();

    $decision->refresh();
    expect($decision->hasCompletePanel())->toBeTrue()
        ->and($decision->presidingJudgeId())->toBe($editor->id);
});

test('only the presiding judge may edit a decision that has one', function (): void {
    $presiding = decisionPanelEditor();
    $otherEditor = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $decision = decisionPanelDecision(jwCase(['status' => 'closed']), decisionPanelOf($first, $presiding, $third));

    $this->actingAs($otherEditor)->get(route('court.decisions.edit', $decision))->assertForbidden();
    $this->actingAs($otherEditor)
        ->patch(route('court.decisions.update', $decision), decisionPanelPayload($decision->courtCase, [$first->id, $otherEditor->id, $third->id]))
        ->assertForbidden();

    $this->actingAs($presiding)->get(route('court.decisions.edit', $decision))->assertOk();
});

test('the decision page cleans the content again and the edit form keeps a custom file number', function (): void {
    $presiding = decisionPanelEditor();
    [$first, $third] = User::factory()->count(2)->create()->all();
    $decision = decisionPanelDecision(
        jwCase(['status' => 'closed']),
        decisionPanelOf($first, $presiding, $third),
        attributes: ['decision_content' => '<p>Held.</p><script>alert(1)</script><img src="x" onerror="alert(2)">', 'case_file_number' => 'FILE-7/2026'],
    );

    $this->actingAs($presiding)
        ->get(route('court.decisions.show', $decision))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('decision.decision_content', fn (string $html): bool => str_contains($html, 'Held.') && ! str_contains($html, '<script') && ! str_contains($html, 'onerror'))
            ->where('decision.panel_complete', true)
            ->etc());

    $this->actingAs($presiding)
        ->get(route('court.decisions.edit', $decision))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('decision.case_file_number', 'FILE-7/2026')->etc());
});
