<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Http\Support\Present;
use Tests\Support\ReportingScenario;

/*
 * In production the application runs in Africa/Addis_Ababa (a system setting
 * applied at boot), while the test environment runs in UTC. A deadline is an
 * instant: it must be stored in UTC and read back as the same instant whatever
 * timezone the application runs in. This file runs the workflow under the
 * production timezone. (Found in the browser: a 17:30 deadline showed as 14:30.)
 */

beforeEach(function (): void {
    Storage::fake('local');
    $this->zone = date_default_timezone_get();
    config(['app.timezone' => 'Africa/Addis_Ababa']);
    date_default_timezone_set('Africa/Addis_Ababa');
    ReportingScenario::clock('2026-11-10 09:00');
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
});

afterEach(function (): void {
    date_default_timezone_set($this->zone);
});

it('stores every moment in UTC and reads back the same instant when the application runs in Addis Ababa time', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    $submission = $this->s->writeAndSubmit($obligation, $this->s->officer('A'));
    $raw = fn (string $table, string $column, string $id): string => substr((string) DB::table($table)->where('id', $id)->value($column), 0, 19);

    // In the tables: UTC. The deadline of 17:30 EAT is 14:30; the submission at 09:00 EAT is 06:00.
    expect($raw('reporting_cycles', 'due_at', $this->cycle->id))->toBe('2026-11-14 14:30:00')
        ->and($raw('reporting_obligations', 'effective_due_at', $obligation->id))->toBe('2026-11-14 14:30:00')
        ->and($raw('reporting_submissions', 'submitted_at', $submission->id))->toBe('2026-11-10 06:00:00')
        ->and($raw('reporting_submissions', 'created_at', $submission->id))->toBe('2026-11-10 06:00:00')
        ->and($raw('reporting_obligations', 'first_submitted_at', $obligation->id))->toBe('2026-11-10 06:00:00')
        // A date is a civil date: it does not shift.
        ->and(substr((string) DB::table('reporting_cycles')->where('id', $this->cycle->id)->value('period_end'), 0, 10))->toBe('2026-11-09');

    // Read back: the same instants, and the same civil date.
    $fresh = $obligation->fresh();
    $row = Present::obligation($fresh->load('cycle.reportType'));
    expect($fresh->effective_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00')
        ->and($fresh->first_submitted_at->toIso8601String())->toBe('2026-11-10T06:00:00+00:00')
        ->and($submission->fresh()->submitted_at->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i'))->toBe('2026-11-10 09:00')
        ->and($this->cycle->fresh()->period_end->toDateString())->toBe('2026-11-09')
        ->and($row['effective_due_at'])->toBe('2026-11-14T14:30:00+00:00')
        ->and($row['timeliness'])->toBe('early');
});

it('judges timeliness on the instant, not on the wall clock of either zone', function (): void {
    $officer = $this->s->officer('A');
    $late = $this->s->officer('B');
    $this->s->write($this->s->obligation($this->cycle, 'A'), $officer);
    $this->s->write($this->s->obligation($this->cycle, 'B'), $late);

    // 17:30 in Addis Ababa on the due date is on time; one minute later is late.
    ReportingScenario::clock('2026-11-14 17:30');
    $this->s->submit($this->s->obligation($this->cycle, 'A'), $officer);
    ReportingScenario::clock('2026-11-14 17:31');
    $this->s->submit($this->s->obligation($this->cycle, 'B'), $late);
    ReportingScenario::clock('2026-11-20 09:00');

    $query = app(ComplianceQuery::class);
    $oversight = $this->s->actor($this->s->admin);
    $rows = collect($query->register($oversight, ['cycle_id' => $this->cycle->id])->items())->keyBy('organization.code');
    $counts = $query->summary($oversight, ['cycle_id' => $this->cycle->id])['counts'];

    expect($rows['RPT-A']['timeliness'])->toBe('on_time')
        ->and($rows['RPT-B']['timeliness'])->toBe('late')
        ->and($rows['RPT-C']['timeliness'])->toBe('overdue')
        ->and([$counts['submitted_on_time'], $counts['submitted_late'], $counts['overdue_unsubmitted']])->toBe([1, 1, 1])
        // The SQL filters agree with the row-by-row classification.
        ->and($query->register($oversight, ['timeliness' => 'on_time'])->total())->toBe(1)
        ->and($query->register($oversight, ['timeliness' => 'late'])->total())->toBe(1)
        ->and($query->register($oversight, ['timeliness' => 'overdue'])->total())->toBe(1)
        // The business day of the deadline, in both filters and the calendar.
        ->and($query->register($oversight, ['due_from' => '2026-11-14', 'due_to' => '2026-11-14'])->total())->toBe(3)
        ->and($query->register($oversight, ['due_from' => '2026-11-15'])->total())->toBe(0)
        ->and(app(WorkQueues::class)->calendar($oversight, '2026-11-14', '2026-11-14')[0]['expected'])->toBe(3)
        ->and(app(WorkQueues::class)->calendar($oversight, '2026-11-15', '2026-11-30'))->toBe([]);
});
