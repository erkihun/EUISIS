<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceDecisionService;
use Modules\Grievance\Domain\Enums\GrievanceVoteType;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;
use Modules\Grievance\Domain\Models\GrievanceDecision;
use Tests\Support\GrievanceScenario;

/*
 * Regulation 201/2018 Art. 8 on a grievance committee recommendation:
 * quorum is the chairperson and two other members (8(2)); it passes by
 * majority, the chairperson's side prevailing on a tie (8(3)); and a
 * dissenting member writes down the reason (8(4)). These hold whatever
 * the voting settings say.
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

function gcvVote(object $test, string $who, GrievanceVoteType $vote, ?string $opinion = null): void
{
    $test->decisions->castVote($test->draft, $test->s->{$who}, $vote, $opinion);
}

function gcvSubmit(object $test): GrievanceDecision
{
    return $test->decisions->submitForApproval($test->draft->refresh(), $test->s->chair);
}

it('needs the chairperson and two other members to have voted', function (): void {
    gcvVote($this, 'chair', GrievanceVoteType::Concur);
    gcvVote($this, 'writer', GrievanceVoteType::Concur);
    expect(fn () => gcvSubmit($this))->toThrow(ValidationException::class, __('grievances.errors.statutory_quorum_not_met'));

    gcvVote($this, 'member', GrievanceVoteType::Concur);
    expect(gcvSubmit($this)->status->value)->toBe('pending_executive_approval');
});

it('refuses a dissent without its reason', function (): void {
    expect(fn () => gcvVote($this, 'member', GrievanceVoteType::Dissent))->toThrow(ValidationException::class, __('grievances.errors.dissent_reason_required'));
    gcvVote($this, 'member', GrievanceVoteType::Dissent, 'The evidence does not show the overtime was ordered.');
    expect($this->draft->votes()->where('vote', 'dissent')->value('opinion'))->not->toBeEmpty();
});

it('does not adopt a recommendation the majority voted against', function (): void {
    gcvVote($this, 'chair', GrievanceVoteType::Concur);
    gcvVote($this, 'writer', GrievanceVoteType::Dissent, 'Not supported by the record.');
    gcvVote($this, 'member', GrievanceVoteType::Dissent, 'Not supported by the record.');

    expect(fn () => gcvSubmit($this))->toThrow(ValidationException::class, __('grievances.errors.recommendation_not_carried'));
});

it('breaks a tie in favour of the chairperson', function (): void {
    // A fourth seat makes a 2–2 tie possible.
    $fourth = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit), $this->s->member->getPermissionNames()->all());
    $seat = GrievanceCommitteeMember::query()->create([
        'committee_id' => $this->s->committee->id, 'employee_id' => $fourth->employee_id, 'role' => 'member',
        'effective_from' => now()->subMonth()->toDateString(), 'status' => 'active',
    ]);
    $this->g->currentStage->members()->create([
        'employee_id' => $fourth->employee_id, 'committee_member_id' => $seat->id, 'role' => 'member', 'source' => 'committee', 'is_active' => true, 'joined_at' => now(),
    ]);
    $this->s->fourth = $fourth;

    gcvVote($this, 'chair', GrievanceVoteType::Dissent, 'The claim is out of time.');
    gcvVote($this, 'member', GrievanceVoteType::Dissent, 'The claim is out of time.');
    gcvVote($this, 'writer', GrievanceVoteType::Concur);
    gcvVote($this, 'fourth', GrievanceVoteType::Concur);
    expect(fn () => gcvSubmit($this))->toThrow(ValidationException::class, __('grievances.errors.recommendation_not_carried'));

    gcvVote($this, 'chair', GrievanceVoteType::Concur);
    gcvVote($this, 'member', GrievanceVoteType::Dissent, 'The claim is out of time.');
    gcvVote($this, 'fourth', GrievanceVoteType::Dissent, 'The claim is out of time.');
    expect(gcvSubmit($this)->status->value)->toBe('pending_executive_approval');
});
