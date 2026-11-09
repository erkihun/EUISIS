<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Discipline\Application\Services\DisciplinaryCaseService;
use Tests\Support\DisciplineScenario;

/*
 * Regulation 201/2018 Art. 20: the accused admits or denies each charge. A
 * later response changes the current plea but never erases what an earlier
 * response said.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = DisciplineScenario::build();
    $this->cases = app(DisciplinaryCaseService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('keeps each response version with the pleas it gave', function (): void {
    $s = $this->s;
    $case = $this->cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence']);
    $this->cases->addCharge($case, $s->officer, ['title' => 'Absence', 'particulars' => 'Absent.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet']);
    $this->cases->assignPanel($case, $s->officer, $s->committee->id);
    $this->cases->fileCharge($case, $s->officer);
    $chargeId = $case->charges()->value('id');

    $this->cases->recordResponse($case->refresh(), $s->accused, ['pleas' => [$chargeId => ['plea' => 'admitted', 'statement' => 'I was absent.']], 'acknowledged_name' => 'A']);
    $this->cases->recordResponse($case->refresh(), $s->accused, ['pleas' => [$chargeId => ['plea' => 'denied', 'statement' => 'I had sick leave.']], 'acknowledged_name' => 'A']);

    $responses = $case->responses()->orderBy('version')->get();
    expect($responses)->toHaveCount(2)
        ->and($responses[0]->pleas[0])->toMatchArray(['charge_id' => $chargeId, 'plea' => 'admitted', 'statement' => 'I was absent.'])
        ->and($responses[1]->pleas[0])->toMatchArray(['plea' => 'denied', 'statement' => 'I had sick leave.'])
        ->and($case->charges()->first()->plea->value)->toBe('denied');
});

it('sets no Regulation 201/2018 deadline on a case begun under Regulation 26/2002 (Art. 56)', function (): void {
    $s = $this->s;
    $case = $this->cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence']);
    $case->forceFill(['governing_regulation' => '26/2002'])->save();
    $this->cases->addCharge($case, $s->officer, ['title' => 'Absence', 'particulars' => 'Absent.', 'occurred_on' => '2025-12-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet']);
    $this->cases->assignPanel($case, $s->officer, $s->committee->id);
    $this->cases->fileCharge($case, $s->officer);

    expect($case->refresh()->deadlines()->count())->toBe(0);
});
