<?php

declare(strict_types=1);

use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Discipline\Application\Services\DisciplinaryCaseService;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;
use Modules\Discipline\Contracts\Data\DisciplinarySeverity;
use Modules\Discipline\Domain\Enums\DisciplinaryTrack;
use Tests\Support\DisciplineScenario;

/*
 * Regulation 201/2018 Art. 13(5): the head examines the expedited inquiry's
 * recommendation and decides — but where the misconduct calls for a serious
 * penalty, the head refers the charge to the disciplinary committee, and the
 * case continues under the formal procedure.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('refers an expedited inquiry to the committee and restarts it on the formal track', function (): void {
    $case = $this->cases->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'expedited', 'severity' => 'minor']);
    $this->cases->addCharge($case, $this->s->officer, ['title' => 'Absence', 'particulars' => 'Absent five days.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet']);
    $this->cases->assignPanel($case, $this->s->officer, null, [$this->s->secretary->employee_id]);
    $this->cases->fileCharge($case, $this->s->officer);
    $this->cases->recordResponse($case->refresh(), $this->s->accused, ['pleas' => [$case->charges()->first()->id => ['plea' => 'admitted']], 'acknowledged_name' => 'A']);
    $this->cases->adoptRecommendation($case->refresh(), $this->s->secretary, ['finding' => 'responsible', 'penalty' => 'written_warning', 'gravity_assessment' => 'Five days of absence is grave.', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'Admitted.']);

    $this->cases->decide($case->refresh(), $this->s->head, ['action' => 'refer_to_committee', 'reason' => 'Five days of unexplained absence calls for a serious penalty.']);
    $case->refresh();

    expect($case->status)->toBe(DisciplinaryCaseStatus::Draft)
        ->and($case->track)->toBe(DisciplinaryTrack::Formal)
        ->and($case->severity)->toBe(DisciplinarySeverity::Serious)
        ->and($case->members()->count())->toBe(0)
        ->and($case->charges()->count())->toBe(1)
        ->and($case->currentDecision()->action->value)->toBe('refer_to_committee')
        ->and($case->currentDecision()->penalty)->toBeNull();

    $this->cases->assignPanel($case, $this->s->officer, $this->s->committee->id);
    $this->cases->fileCharge($case->refresh(), $this->s->officer);
    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::Charged)
        ->and($case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_INVESTIGATION)->exists())->toBeTrue();
});

it('does not refer a case that is already before the committee', function (): void {
    $s = $this->s;
    $case = $this->cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence']);
    $this->cases->addCharge($case, $s->officer, ['title' => 'Absence', 'particulars' => 'Absent.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet']);
    $this->cases->assignPanel($case, $s->officer, $s->committee->id);
    $this->cases->fileCharge($case, $s->officer);
    $case->refresh();
    $this->cases->recordResponse($case, $s->accused, ['pleas' => $case->charges()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['plea' => 'admitted']])->all(), 'acknowledged_name' => 'A']);
    $this->cases->recordFinalStatement($case->refresh(), $s->accused, 'Nothing to add.', false);
    $seat = fn ($user) => $case->members()->where('employee_id', $user->employee_id)->firstOrFail()->id;
    $this->cases->adoptRecommendation($case->refresh(), $s->chair, [
        'finding' => 'responsible', 'penalty' => 'written_warning', 'gravity_assessment' => 'g', 'conduct_assessment' => 'c', 'prior_record_assessment' => 'p', 'reasoning' => 'r',
        'votes' => [$seat($s->chair) => ['vote' => 'for'], $seat($s->appointed) => ['vote' => 'for'], $seat($s->elected) => ['vote' => 'for']],
    ]);

    expect(fn () => $this->cases->decide($case->refresh(), $s->head, ['action' => 'refer_to_committee', 'reason' => 'x']))
        ->toThrow(ValidationException::class, __('discipline.errors.refer_expedited_only'));
    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::ReportSubmitted);
});
