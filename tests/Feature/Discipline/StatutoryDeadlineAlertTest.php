<?php

declare(strict_types=1);

use App\Notifications\StatutoryDeadlineNotification;
use App\Support\Legal\Regulation201;
use App\Support\NotificationPresenter;
use Illuminate\Support\Carbon;
use Modules\Discipline\Application\Services\DisciplinaryCaseService;
use Tests\Support\DisciplineScenario;

/*
 * legal:statutory-deadline-alerts warns the case officers once when a
 * deadline the institution owes is two working days away, and once more
 * when it has passed.
 */

afterEach(fn () => Carbon::setTestNow());

it('alerts the case officers once before a deadline and once when it is overdue', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $s = DisciplineScenario::build();
    $cases = app(DisciplinaryCaseService::class);
    $case = $cases->open($s->officer, $s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence']);
    $cases->addCharge($case, $s->officer, ['title' => 'Absence', 'particulars' => 'Absent.', 'occurred_on' => '2026-09-01', 'occurred_place' => 'Records Team', 'violated_provision' => 'Proclamation 87/2017 Art. 95', 'evidence_list' => 'Attendance sheet']);
    $cases->assignPanel($case, $s->officer, $s->committee->id);
    $cases->fileCharge($case, $s->officer);
    $due = $case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_INVESTIGATION)->value('due_at');
    $count = fn () => $s->officer->notifications()->where('type', StatutoryDeadlineNotification::class)->count();

    $this->artisan('legal:statutory-deadline-alerts')->assertExitCode(0);
    expect($count())->toBe(0);

    Carbon::setTestNow(Carbon::parse($due)->subDay()->setTime(7, 0));
    $this->artisan('legal:statutory-deadline-alerts')->assertExitCode(0);
    $this->artisan('legal:statutory-deadline-alerts')->assertExitCode(0);
    expect($count())->toBe(1);

    Carbon::setTestNow(Carbon::parse($due)->addDay());
    $this->artisan('legal:statutory-deadline-alerts')->assertExitCode(0);
    expect($count())->toBe(2);

    $shown = NotificationPresenter::present($s->officer->notifications()->latest()->first(), 'en');
    expect($shown['message'])->toContain($case->case_number)->toContain('overdue')
        ->and($shown['url'])->toBe('/discipline/cases/'.$case->id);
});
