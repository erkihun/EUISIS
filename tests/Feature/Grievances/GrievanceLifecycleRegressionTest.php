<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Models\GrievanceRoute;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievanceDecisionService;
use App\Services\Grievances\GrievanceEscalationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\GrievanceScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();
    $cases = app(GrievanceCaseService::class);
    $this->g = $cases->intakeAccept($cases->submit($cases->createDraft($this->s->complainant, [
        'subject' => 'Case', 'description' => 'Private', 'category_id' => $this->s->category->id,
    ]), $this->s->complainant), $this->s->intake, null);
    $this->decisions = app(GrievanceDecisionService::class);
    $this->letters = app(GrievanceCorrespondenceService::class);
    $this->decision = $this->decisions->createDraft($this->g, $this->s->writer, ['decision_type' => 'not_upheld', 'decision_text' => 'Not upheld.']);
});

afterEach(fn () => Carbon::setTestNow());

it('rejects historical decision edits and finalization after escalation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($this->g->current_stage_id);

    expect(fn () => $this->decisions->updateDraft($this->decision->fresh(), $this->s->teamLead, ['decision_text' => 'Changed']))->toThrow(ValidationException::class);
    expect(fn () => $this->decisions->finalize($this->decision->fresh(), $this->s->teamLead))->toThrow(ValidationException::class);
    expect($this->decision->refresh()->decision_text)->toBe('Not upheld.');
});

it('rechecks draft letter state when a stale edit follows finalization', function (): void {
    $decision = $this->decisions->finalize($this->decision, $this->s->chair);
    $letter = $this->letters->generate($this->g->fresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'en', 'decision_id' => $decision->id]);
    $before = $letter->body;
    $this->letters->finalize($letter->fresh(), $this->s->chair);

    expect(fn () => $this->letters->updateDraft($letter, $this->s->writer, ['body' => 'Changed', 'recipients' => []]))->toThrow(ValidationException::class);
    expect($letter->refresh()->body)->toBe($before)
        ->and($letter->recipients()->count())->toBe(1);
});

it('rejects first issuance from an escalated stage before storing a PDF', function (): void {
    $decision = $this->decisions->finalize($this->decision, $this->s->chair);
    $letter = $this->letters->generate($this->g->fresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'en', 'decision_id' => $decision->id]);
    $letter = $this->letters->sign($this->letters->finalize($letter, $this->s->chair), $this->s->chair, GrievanceSignatureMethod::ElectronicApproval);
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($this->g->current_stage_id);

    expect(fn () => $this->letters->issue($letter->fresh(), $this->s->teamLead))->toThrow(ValidationException::class);
    expect($letter->refresh()->status)->toBe(GrievanceLetterStatus::Signed)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('issues a corrected decision letter without resetting the decision or appeal clock', function (): void {
    $decision = $this->decisions->finalize($this->decision, $this->s->chair);
    $letter = $this->letters->generate($this->g->fresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'en', 'decision_id' => $decision->id]);
    $letter = $this->letters->issue($this->letters->sign($this->letters->finalize($letter, $this->s->chair), $this->s->chair, GrievanceSignatureMethod::ElectronicApproval), $this->s->chair);
    $issuedAt = $decision->fresh()->issued_at->toIso8601String();
    $deadline = $this->g->fresh()->appeal_deadline_at?->toIso8601String();
    $oldPdf = Storage::disk('local')->get($letter->pdf_path);
    Carbon::setTestNow(now()->addDay());
    $voided = $this->letters->void($letter, $this->s->chair, 'Correct spelling');
    $replacement = $this->letters->revise($voided, $this->s->writer);
    $replacement = $this->letters->issue($this->letters->sign($this->letters->finalize($replacement, $this->s->chair), $this->s->chair, GrievanceSignatureMethod::ElectronicApproval), $this->s->chair);

    expect($replacement->status)->toBe(GrievanceLetterStatus::Issued)
        ->and($replacement->reference_number)->not->toBe($letter->reference_number)
        ->and($decision->fresh()->status)->toBe(GrievanceDecisionStatus::Issued)
        ->and($decision->fresh()->issued_at->toIso8601String())->toBe($issuedAt)
        ->and($this->g->fresh()->appeal_deadline_at?->toIso8601String())->toBe($deadline)
        ->and(Storage::disk('local')->get($letter->pdf_path))->toBe($oldPdf);
});

it('binds configured routes for deactivation and approval', function (): void {
    $this->s->outsider->givePermissionTo(['grievance_routes.manage', 'grievance_routes.approve']);
    $route = GrievanceRoute::query()->firstOrFail();
    $count = GrievanceRoute::query()->count();
    $this->actingAs($this->s->outsider)->patch(route('grievances.routes.update', $route), ['deactivate' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($route->refresh()->is_active)->toBeFalse();
    $this->post(route('grievances.routes.approve', $route))->assertRedirect()->assertSessionHasNoErrors();
    expect($route->refresh()->approved_by)->toBe($this->s->outsider->id)
        ->and(GrievanceRoute::query()->count())->toBe($count);
});
