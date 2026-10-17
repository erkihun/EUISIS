<?php

declare(strict_types=1);

use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryDecision;
use App\Services\Discipline\DisciplinaryCaseService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\DisciplineScenario;
use Tests\Support\GrievanceScenario;

/*
 * Who may sit on a disciplinary case. A seat opens the whole confidential
 * record, so a committee member, an expedited-inquiry investigator (Art. 13(1))
 * or a recusal substitute (Art. 9(2)) must be an active employee of the case's
 * institution and must not have been disciplined in the last two years (Art. 6(2)).
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

function dseExpedited(object $test): DisciplinaryCase
{
    $case = $test->cases->open($test->s->officer, $test->s->accusedEmployee(), ['track' => 'expedited', 'severity' => 'minor', 'summary' => 'Late arrival']);
    $test->cases->addCharge($case, $test->s->officer, [
        'title' => 'Late arrival', 'particulars' => 'Late on three days.', 'occurred_on' => '2026-09-01',
        'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 94', 'evidence_list' => 'Attendance sheet',
    ]);

    return $case->refresh();
}

it('refuses an investigator from another institution', function (): void {
    $case = dseExpedited($this);

    expect(fn () => $this->cases->assignPanel($case, $this->s->officer, null, [$this->s->outsider->employee_id]))
        ->toThrow(ValidationException::class);
    expect($case->members()->count())->toBe(0);

    $this->cases->assignPanel($case, $this->s->officer, null, [$this->s->bystander->employee_id]);
    expect($case->members()->pluck('employee_id')->all())->toBe([$this->s->bystander->employee_id]);
});

it('refuses an investigator disciplined in the last two years, unless the penalty was reversed on appeal', function (): void {
    // A penalty decided a year ago against the would-be investigator.
    $previous = DisciplinaryCase::query()->forceCreate([
        'case_number' => 'DC-OLD-1', 'accused_employee_id' => $this->s->bystander->employee_id, 'organization_id' => $this->s->institution->id,
        'track' => 'expedited', 'severity' => 'minor', 'status' => 'closed', 'summary' => 'Earlier matter', 'created_by' => $this->s->officer->id,
    ]);
    $decision = DisciplinaryDecision::query()->forceCreate([
        'case_id' => $previous->id, 'action' => 'approve', 'finding' => 'responsible', 'penalty' => 'written_warning',
        'reason' => 'Earlier decision.', 'decided_by' => $this->s->head->id, 'decided_at' => now()->subYear(),
    ]);
    $case = dseExpedited($this);

    expect(fn () => $this->cases->assignPanel($case, $this->s->officer, null, [$this->s->bystander->employee_id]))
        ->toThrow(ValidationException::class);

    $decision->appeal()->forceCreate([
        'case_id' => $previous->id, 'notice_received_at' => now()->subMonths(11), 'outcome' => 'reversed',
        'outcome_decided_on' => now()->subMonths(6)->toDateString(), 'outcome_received_at' => now()->subMonths(6),
    ]);
    $this->cases->assignPanel($case->refresh(), $this->s->officer, null, [$this->s->bystander->employee_id]);
    expect($case->members()->count())->toBe(1);
});

it('refuses a recusal substitute from another institution, and seats one from the case institution', function (): void {
    $case = $this->cases->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence']);
    $this->cases->addCharge($case, $this->s->officer, [
        'title' => 'Absence', 'particulars' => 'Absent.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team',
        'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet',
    ]);
    $this->cases->assignPanel($case, $this->s->officer, $this->s->committee->id);
    $member = $case->members()->where('employee_id', $this->s->appointed->employee_id)->firstOrFail();

    expect(fn () => $this->cases->recuse($case->refresh(), $this->s->officer, $member, 'Related to the accused.', $this->s->outsider->employee_id))
        ->toThrow(ValidationException::class);
    expect($member->refresh()->recused_at)->toBeNull();

    $substitute = GrievanceScenario::employee($this->s->institution, $this->s->unit);
    $this->cases->recuse($case->refresh(), $this->s->officer, $member, 'Related to the accused.', $substitute->id);
    expect($case->members()->where('employee_id', $substitute->id)->exists())->toBeTrue();
});
