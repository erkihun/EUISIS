<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceCaseOfficerRole;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Enums\Grievance\GrievanceSlaPauseReason;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceLetter;
use App\Models\GrievanceRoute;
use App\Models\GrievanceSlaProfile;
use App\Services\Grievances\GrievanceAppealService;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievanceDecisionService;
use App\Services\Grievances\GrievanceEscalationService;
use App\Services\Grievances\GrievanceSlaService;
use App\Support\Grievances\GrievanceRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\GrievanceScenario;

beforeEach(function (): void {
    // A Monday, so working-day arithmetic is easy to read.
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = GrievanceScenario::build();
    $this->cases = app(GrievanceCaseService::class);
    $this->access = app(GrievanceCaseAccessService::class);
});

afterEach(fn () => Carbon::setTestNow());

function fileAndAccept(object $test): Grievance
{
    $g = $test->cases->createDraft($test->s->complainant, [
        'subject' => 'Unfair shift allocation',
        'description' => 'Confidential narrative.',
        'category_id' => $test->s->category->id,
        'incident_date' => '2026-09-20',
    ]);
    $g = $test->cases->submit($g, $test->s->complainant);

    return $test->cases->intakeAccept($g, $test->s->intake, 'Complete');
}

it('derives origin and routing server-side and assigns a stable case number at submission', function (): void {
    $draft = $this->cases->createDraft($this->s->complainant, [
        'subject' => 'S', 'description' => 'D', 'category_id' => $this->s->category->id,
        // Anything the employee might try to set is ignored.
        'organization_id' => $this->s->bureau->id, 'current_handler_id' => $this->s->directorate->id,
    ]);
    expect($draft->organization_id)->toBe($this->s->woreda->id)
        ->and($draft->reference_number)->toStartWith('DRAFT-')
        ->and($draft->current_handler_id)->toBeNull();

    $submitted = $this->cases->submit($draft, $this->s->complainant);
    expect($submitted->reference_number)->toMatch('/^GRV-2026-\d{6}$/')
        ->and($submitted->status)->toBe(GrievanceStatus::Submitted);

    $number = $submitted->reference_number;
    $accepted = $this->cases->intakeAccept($submitted, $this->s->intake, null);
    $stage = $accepted->currentStage;

    expect($accepted->reference_number)->toBe($number)
        ->and($stage->handler_type->value)->toBe('committee')
        ->and($stage->handler_id)->toBe($this->s->committee->id)
        ->and($stage->movement_type)->toBe(GrievanceMovementType::InitialAssignment)
        ->and($stage->sla_days)->toBe(3)
        // Monday + 3 working days = end of Thursday.
        ->and($stage->due_at->toDateString())->toBe('2026-10-08')
        ->and($stage->members()->count())->toBe(3);
});

it('keeps a submitted grievance immutable to the complainant', function (): void {
    $g = fileAndAccept($this);
    expect(fn () => $this->cases->updateDraft($g, $this->s->complainant, ['subject' => 'changed']))->toThrow(ValidationException::class);
    expect(fn () => $this->cases->deleteDraft($g, $this->s->complainant))->toThrow(ValidationException::class);
});

it('lets an intake return be corrected with the change recorded as an amendment', function (): void {
    $g = $this->cases->submit($this->cases->createDraft($this->s->complainant, ['subject' => 'Old', 'description' => 'D', 'category_id' => $this->s->category->id]), $this->s->complainant);
    $g = $this->cases->intakeReturn($g, $this->s->intake, 'INCOMPLETE_INFORMATION', 'Add dates');
    $this->cases->updateDraft($g, $this->s->complainant, ['subject' => 'New']);

    expect($g->amendments()->count())->toBe(1)
        ->and($g->amendments()->first()->changes['subject'])->toBe(['from' => 'Old', 'to' => 'New']);
    $number = $g->refresh()->reference_number;
    expect($this->cases->submit($g, $this->s->complainant)->reference_number)->toBe($number);
});

it('escalates an overdue stage exactly once, even when the job runs twice', function (): void {
    $g = fileAndAccept($this);
    $stageId = $g->current_stage_id;

    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    $escalation = app(GrievanceEscalationService::class);
    expect($escalation->autoEscalate($stageId))->toBe('escalated')
        ->and($escalation->autoEscalate($stageId))->toBe('skipped');
    $escalation->processDueStages();

    $g->refresh();
    expect(GrievanceCaseStage::query()->where('grievance_id', $g->id)->count())->toBe(2)
        ->and($g->currentStage->handler_id)->toBe($this->s->team->id)
        ->and($g->currentStage->movement_type)->toBe(GrievanceMovementType::TimeoutEscalation)
        ->and(GrievanceCaseStage::find($stageId)->status)->toBe(GrievanceStageStatus::Escalated)
        // The team stage got its own (10-day) SLA, not the committee's.
        ->and($g->currentStage->sla_days)->toBe(10);
});

it('records a blocked escalation once when no route is configured', function (): void {
    GrievanceRoute::query()->where('movement_type', 'timeout_escalation')->delete();
    $g = fileAndAccept($this);

    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
    $escalation = app(GrievanceEscalationService::class);
    expect($escalation->autoEscalate($g->current_stage_id))->toBe('blocked');
    $escalation->autoEscalate($g->current_stage_id);

    expect($g->events()->where('event', 'escalation_blocked')->count())->toBe(1)
        ->and($g->refresh()->currentStage->stage_no)->toBe(1);
});

it('extends the deadline by paused working days and never escalates a paused stage', function (): void {
    $g = fileAndAccept($this);
    $stage = $g->currentStage;
    $sla = app(GrievanceSlaService::class);

    // A member without grievances.sla_pause only requests; the chair's request starts at once.
    $pause = $sla->requestPause($stage, GrievanceSlaPauseReason::AwaitingEmployeeInformation, null, $this->s->chair);
    expect($pause->status->value)->toBe('active');

    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00')); // one week later, past the original due date
    expect(app(GrievanceEscalationService::class)->autoEscalate($stage->id))->toBe('skipped');

    $sla->resume($pause, $this->s->chair);
    $stage->refresh();
    expect($stage->original_due_at->toDateString())->toBe('2026-10-08')
        ->and($stage->paused_days)->toBe(5)
        ->and($stage->due_at->toDateString())->toBe('2026-10-15');
});

it('snapshots the SLA so a later policy change does not rewrite the deadline', function (): void {
    $g = fileAndAccept($this);
    GrievanceSlaProfile::query()->whereKey($this->s->committeeSla->id)->update(['resolution_days' => 20]);

    expect($g->currentStage->refresh()->due_at->toDateString())->toBe('2026-10-08');
});

it('grants case access by relationship, never by permission alone', function (): void {
    $g = fileAndAccept($this);

    expect($this->access->canView($this->s->chair, $g))->toBeTrue()
        ->and($this->access->canView($this->s->member, $g))->toBeTrue()
        ->and($this->access->canView($this->s->complainant, $g))->toBeTrue()
        ->and($this->access->canSeeInternalNotes($this->s->complainant, $g))->toBeFalse()
        // Every grievance permission, same organization, no relationship.
        ->and($this->access->canView($this->s->outsider, $g))->toBeFalse()
        ->and($this->access->canView($this->s->teamLead, $g))->toBeFalse();

    expect(Grievance::query()->tap(fn ($q) => $this->access->constrainAuthorized($q, $this->s->outsider))->count())->toBe(0)
        ->and(Grievance::query()->tap(fn ($q) => $this->access->constrainAssigned($q, $this->s->writer))->count())->toBe(1);

    // After escalation the team sees it; the committee keeps read-only history.
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($g->current_stage_id);
    $g->refresh();
    expect($this->access->canView($this->s->teamLead, $g))->toBeTrue()
        ->and($this->access->handlesCurrent($this->s->chair, $g))->toBeFalse()
        ->and($this->access->canView($this->s->chair, $g))->toBeTrue()
        ->and($this->access->canReview($this->s->chair, $g))->toBeFalse();
});

it('never lets the complainant or a respondent handle the case', function (): void {
    $g = fileAndAccept($this);
    $g->forceFill(['respondent_employee_id' => $this->s->member->employee_id])->save();

    expect($this->access->handlesCurrent($this->s->member, $g->refresh()))->toBeFalse()
        ->and($this->access->canView($this->s->member, $g))->toBeFalse();
});

it('scopes intake review to the officer organization', function (): void {
    $other = GrievanceScenario::employee($this->s->subcity, $this->s->team);
    $employeeUser = GrievanceScenario::user($other, GrievanceRoles::EMPLOYEE_PERMISSIONS);
    $g = $this->cases->submit($this->cases->createDraft($employeeUser, ['subject' => 'S', 'description' => 'D', 'category_id' => $this->s->category->id]), $employeeUser);

    expect($this->access->canIntake($this->s->intake, $g))->toBeFalse();
    expect(fn () => $this->cases->intakeAccept($g, $this->s->intake, null))->toThrow(AuthorizationException::class);
});

it('removes a recused member from the case panel and restores quorum with a replacement', function (): void {
    $g = fileAndAccept($this);
    $committees = app(GrievanceCommitteeService::class);

    $recusal = $committees->declareRecusal($g, $this->s->member, 'Related to the respondent');
    expect($this->access->canReview($this->s->member, $g))->toBeFalse();

    $replacement = GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit);
    $committees->decideRecusal($recusal, $this->s->chair, true, 'Approved', $replacement->id);

    expect($this->access->canView($this->s->member, $g->refresh()))->toBeFalse()
        ->and($g->currentStage->members()->where('is_active', true)->count())->toBe(3)
        ->and($g->currentStage->members()->where('employee_id', $replacement->id)->value('source'))->toBe('replacement');
});

it('enforces committee composition from settings', function (): void {
    $committees = app(GrievanceCommitteeService::class);
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $employee = GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit);

    expect(fn () => $committees->addMember($this->s->committee, $admin, ['employee_id' => $employee->id, 'role' => 'chairperson', 'effective_from' => now()->toDateString()]))->toThrow(ValidationException::class)
        ->and(fn () => $committees->addMember($this->s->committee, $admin, ['employee_id' => $employee->id, 'role' => 'writer', 'effective_from' => now()->toDateString()]))->toThrow(ValidationException::class);

    $committees->addMember($this->s->committee, $admin, ['employee_id' => $employee->id, 'role' => 'member', 'effective_from' => now()->toDateString()]);
    $sixth = GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit);
    $committees->addMember($this->s->committee, $admin, ['employee_id' => $sixth->id, 'role' => 'member', 'effective_from' => now()->toDateString()]);
    $seventh = GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit);
    expect(fn () => $committees->addMember($this->s->committee, $admin, ['employee_id' => $seventh->id, 'role' => 'member', 'effective_from' => now()->toDateString()]))->toThrow(ValidationException::class);

    // Ending a term keeps the history row.
    $member = $this->s->committee->members()->where('employee_id', $sixth->id)->first();
    $committees->endMember($this->s->committee, $member, $admin, 'Term ended');
    expect($member->refresh()->status)->toBe('inactive')->and($member->effective_to)->not->toBeNull();
});

it('versions decisions, enforces separation of duties and requires the configured approver', function (): void {
    $this->s->requireDirectorateApproval();
    $g = fileAndAccept($this);
    // Move to the directorate: two timeouts.
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($g->current_stage_id);
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($g->refresh()->current_stage_id);
    $g->refresh();
    expect($g->currentStage->handler_id)->toBe($this->s->directorate->id);

    $decisions = app(GrievanceDecisionService::class);
    $v1 = $decisions->createDraft($g, $this->s->directorateOfficer, ['decision_type' => 'upheld', 'decision_text' => 'Upheld.', 'findings' => 'F']);
    $v1 = $decisions->submitForApproval($v1, $this->s->directorateOfficer);
    expect($v1->status)->toBe(GrievanceDecisionStatus::PendingExecutiveApproval);

    // Preparer cannot approve; an unrelated approver-permission holder cannot either.
    expect($this->access->canApprove($this->s->directorateOfficer, $v1))->toBeFalse();
    $otherApprover = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::APPROVER_PERMISSIONS);
    expect($this->access->canApprove($otherApprover, $v1))->toBeFalse()
        ->and($this->access->canApprove($this->s->approver, $v1))->toBeTrue();

    expect(fn () => $decisions->returnForCorrection($v1, $this->s->approver, ''))->toThrow(ValidationException::class);
    $decisions->returnForCorrection($v1, $this->s->approver, 'Cite the directive.');

    $v2 = $decisions->createRevision($v1->refresh(), $this->s->directorateOfficer, ['legal_basis' => 'Directive 1/2020']);
    expect($v2->version_no)->toBe(2)->and($v1->refresh()->status)->toBe(GrievanceDecisionStatus::ReturnedForCorrection);
    $v2 = $decisions->submitForApproval($v2, $this->s->directorateOfficer);
    expect($v2->status)->toBe(GrievanceDecisionStatus::Resubmitted);

    $decisions->approve($v2, $this->s->approver, null);
    // A second approval (double click / second approver) fails cleanly on the locked, re-read row.
    expect(fn () => $decisions->approve($v2, $this->s->approver, null))->toThrow(ValidationException::class);
    expect(fn () => $decisions->approve($v2->refresh(), $this->s->approver, null))->toThrow(AuthorizationException::class);

    $v2 = $decisions->finalize($v2->refresh(), $this->s->directorateOfficer);
    expect($v2->status)->toBe(GrievanceDecisionStatus::Finalized)->and($v2->decision_no)->toMatch('/^GRD-2026-\d{6}$/')
        ->and($g->refresh()->status)->not->toBe(GrievanceStatus::DecisionIssued);
});

it('issues an immutable decision letter, then accepts one appeal routed to the next level', function (): void {
    $g = fileAndAccept($this);
    GrievanceScenario::route('committee', $this->s->committee->id, 'organization_unit', $this->s->team->id, 'employee_appeal');

    $decisions = app(GrievanceDecisionService::class);
    $d = $decisions->createDraft($g, $this->s->writer, ['decision_type' => 'not_upheld', 'decision_text' => 'Not upheld.']);
    // No approval rule: the chairperson finalizes directly.
    $d = $decisions->finalize($d, $this->s->chair);

    $letters = app(GrievanceCorrespondenceService::class);
    $letter = $letters->generate($g->refresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'am', 'decision_id' => $d->id]);
    expect($letter->body)->toContain($g->reference_number)->not->toContain('{{case_number}}');

    $letter = $letters->finalize($letter, $this->s->chair);
    expect($letter->reference_number)->toMatch('/^GRL-2026-\d{6}$/');
    expect(fn () => $letters->sign($letter, $this->s->chair, GrievanceSignatureMethod::QualifiedDigitalSignature))->toThrow(ValidationException::class);
    $letter = $letters->sign($letter, $this->s->chair, GrievanceSignatureMethod::ElectronicApproval);
    $letter = $letters->issue($letter, $this->s->chair);

    expect($letter->status)->toBe(GrievanceLetterStatus::Issued)
        ->and($letter->pdf_sha256)->toHaveLength(64)
        ->and($d->refresh()->status)->toBe(GrievanceDecisionStatus::Issued)
        ->and($g->refresh()->status)->toBe(GrievanceStatus::DecisionIssued);

    // Issued letters are immutable at the model level.
    expect(fn () => GrievanceLetter::query()->find($letter->id)->update(['body' => 'tampered']))->toThrow(LogicException::class);
    expect($this->access->canDownloadDocument($this->s->complainant, $letter))->toBeTrue();

    $appeals = app(GrievanceAppealService::class);
    $appeal = $appeals->file($g, $this->s->complainant, 'The decision ignored my evidence.');
    expect($appeal->toStage->movement_type)->toBe(GrievanceMovementType::EmployeeAppeal)
        ->and($g->refresh()->currentStage->handler_id)->toBe($this->s->team->id);
    expect(fn () => $appeals->file($g->refresh(), $this->s->complainant, 'again'))->toThrow(ValidationException::class);
});

it('requires handler approval for withdrawal once review has started', function (): void {
    $g = fileAndAccept($this);
    $this->cases->startReview($g, $this->s->chair);

    $g = $this->cases->withdraw($g->refresh(), $this->s->complainant, 'PERSONAL_DECISION', null);
    expect($g->status)->toBe(GrievanceStatus::WithdrawRequested);

    $g = $this->cases->decideWithdrawal($g, $this->s->chair, true, null);
    expect($g->status)->toBe(GrievanceStatus::Withdrawn)->and($g->record_state->value)->toBe('closed');
});

it('lets a team assigning officer distribute cases to officers of the same unit only', function (): void {
    $g = fileAndAccept($this);
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($g->current_stage_id);
    $g->refresh();

    $this->cases->assignOfficer($g, $this->s->teamLead, $this->s->teamOfficer, GrievanceCaseOfficerRole::Officer);
    expect($this->access->canView($this->s->teamOfficer, $g))->toBeTrue();

    expect(fn () => $this->cases->assignOfficer($g, $this->s->teamLead, $this->s->directorateOfficer, GrievanceCaseOfficerRole::Officer))->toThrow(ValidationException::class);
});
