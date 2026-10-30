<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Models\GrievanceApprovalRule;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievanceDecisionService;
use App\Support\Grievances\GrievanceRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinaryDecision;
use Tests\Support\GrievanceScenario;

/*
 * Regulation 201/2018 Arts. 30(1), 34(3) and 35(1): the grievance committee
 * examines the grievance and recommends; the head of the institution decides.
 * A committee decision is never final on the committee's own say-so.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();
    $cases = app(GrievanceCaseService::class);
    $this->g = $cases->intakeAccept($cases->submit($cases->createDraft($this->s->complainant, [
        'subject' => 'Case', 'description' => 'Private', 'category_id' => $this->s->category->id,
    ]), $this->s->complainant), $this->s->intake, null);
    $this->decisions = app(GrievanceDecisionService::class);
    $this->draft = $this->decisions->createDraft($this->g, $this->s->writer, ['decision_type' => 'upheld', 'decision_text' => 'Upheld.']);
});

afterEach(fn () => Carbon::setTestNow());

it('does not let the committee finalize its own recommendation', function (): void {
    expect(fn () => $this->decisions->finalize($this->draft, $this->s->chair))->toThrow(ValidationException::class);
    expect($this->draft->refresh()->status)->toBe(GrievanceDecisionStatus::Draft);

    $final = $this->s->headDecides($this->draft->refresh(), $this->s->chair);
    expect($final->status)->toBe(GrievanceDecisionStatus::Finalized)
        ->and($final->requires_executive_approval)->toBeTrue();
});

it('does not let an approval rule waive the head decision for a committee', function (): void {
    GrievanceApprovalRule::query()->update(['is_active' => false]);
    GrievanceApprovalRule::query()->create([
        'name_en' => 'No approval', 'handler_type' => 'committee', 'handler_id' => $this->s->committee->id,
        'requires_approval' => false, 'priority' => 1, 'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
    ]);

    $this->s->committeeVotes($this->draft);
    expect(fn () => $this->decisions->finalize($this->draft, $this->s->chair))->toThrow(ValidationException::class);
    try {
        $this->decisions->submitForApproval($this->draft, $this->s->chair);
        $this->fail('Expected the missing head-decision rule to be reported.');
    } catch (ValidationException $e) {
        expect($e->errors()['decision'][0])->toBe(__('grievances.errors.head_decision_rule_missing'));
    }
});

it('issues the written decision only with the signature and the institution seal', function (): void {
    $decision = $this->s->headDecides($this->draft, $this->s->chair);
    $letters = app(GrievanceCorrespondenceService::class);
    $letter = $letters->generate($this->g->fresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'en', 'decision_id' => $decision->id]);
    $letter = $letters->sign($letters->finalize($letter, $this->s->chair), $this->s->chair, GrievanceSignatureMethod::ElectronicApproval);

    expect(fn () => $letters->issue($letter, $this->s->chair))->toThrow(ValidationException::class, __('grievances.errors.decision_letter_seal_required'));
    expect($letter->refresh()->status)->toBe(GrievanceLetterStatus::Signed);

    expect($letters->issue(GrievanceScenario::seal($letter), $this->s->chair)->status)->toBe(GrievanceLetterStatus::Issued);
});

it('keeps an employee disciplined in the last two years off the grievance committee', function (): void {
    $committees = app(GrievanceCommitteeService::class);
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $employee = GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit);
    $previous = DisciplinaryCase::query()->forceCreate([
        'case_number' => 'DC-GRV-1', 'accused_employee_id' => $employee->id, 'organization_id' => $this->s->woreda->id,
        'track' => 'expedited', 'severity' => 'minor', 'status' => 'closed', 'summary' => 'Earlier matter', 'created_by' => $admin->id,
    ]);
    DisciplinaryDecision::query()->forceCreate([
        'case_id' => $previous->id, 'action' => 'approve', 'finding' => 'responsible', 'penalty' => 'written_warning',
        'reason' => 'Earlier decision.', 'decided_by' => $admin->id, 'decided_at' => now()->subYear(),
    ]);

    expect(fn () => $committees->addMember($this->s->committee, $admin, ['employee_id' => $employee->id, 'role' => 'member', 'effective_from' => now()->toDateString()]))
        ->toThrow(ValidationException::class, __('grievances.errors.member_disciplined'));
});

it('shows on the settings page what the regulation still needs configured', function (): void {
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);

    $this->actingAs($admin)->get(route('grievances.settings.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('statutory.head_decision_rules', 1)
            ->where('statutory.approved_seals', 0)
            ->where('statutory.work_week_days', [1, 2, 3, 4, 5]));

    GrievanceApprovalRule::query()->where('handler_type', 'committee')->update(['is_active' => false]);
    $this->actingAs($admin)->get(route('grievances.settings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('statutory.head_decision_rules', 0));
});
