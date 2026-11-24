<?php

declare(strict_types=1);

use App\Models\Organization;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\UseCases\ConfigureReportTypes;
use Modules\Reporting\Application\UseCases\ManageDeadlines;
use Modules\Reporting\Application\UseCases\ManageSchedules;
use Modules\Reporting\Application\UseCases\PublishCycles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ObligationEvent;
use Tests\Support\ReportingScenario;

/*
 * Publishing a cycle and the expected obligations it creates: exactly once
 * per institution, visible as missing when nothing is ever done, with the
 * names and structure of the day kept as a snapshot.
 */

beforeEach(function (): void {
    ReportingScenario::clock();
    $this->s = ReportingScenario::build()->configure();
});

it('creates one expected obligation for every required institution, exactly once', function (): void {
    $cycle = $this->s->publish();

    expect(Obligation::query()->where('cycle_id', $cycle->id)->count())->toBe(3)
        ->and($cycle->period_key)->toBe('EC-2019-M02')
        ->and($cycle->period_label_am)->toBe('ጥቅምት 2019 ዓ.ም')
        // Tikimt 30 is 9 November; five days later at 17:30 in Addis Ababa is 14:30 UTC.
        ->and($cycle->due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00')
        ->and($cycle->opens_at->toIso8601String())->toBe('2026-11-01T21:00:00+00:00');

    // Publishing again, by hand and by the scheduler, adds nothing.
    $again = $this->s->publish('2026-11-05');
    $stats = app(PublishCycles::class)->publishDue();

    expect($again->id)->toBe($cycle->id)
        ->and(Cycle::query()->where('series_key', $this->s->schedule->id)->where('period_key', 'EC-2019-M02')->count())->toBe(1)
        ->and(Obligation::query()->where('cycle_id', $cycle->id)->count())->toBe(3)
        ->and(Obligation::query()->where('cycle_id', $cycle->id)->distinct()->count('subject_key'))->toBe(3)
        // The scheduler also published Hidar, which opened on 2 December minus 7 days... not yet: only what has opened.
        ->and($stats['obligations'])->toBe(0);
});

it('lets the scheduler catch up on periods it missed', function (): void {
    // Nothing was published by hand. The clock is 20 November: Tikimt opened on 2 November.
    $stats = app(PublishCycles::class)->publishDue();

    expect($stats)->toBe(['cycles' => 1, 'obligations' => 3]);

    // The scheduler is then down until 12 January: Hidar and Tahsas have opened meanwhile.
    ReportingScenario::clock('2027-01-12 09:00');
    $stats = app(PublishCycles::class)->publishDue();

    expect($stats)->toBe(['cycles' => 2, 'obligations' => 6])
        ->and(Cycle::query()->orderBy('period_end')->pluck('period_key')->all())->toBe(['EC-2019-M02', 'EC-2019-M03', 'EC-2019-M04'])
        ->and(app(PublishCycles::class)->publishDue())->toBe(['cycles' => 0, 'obligations' => 0]);
});

it('shows an institution that never opened the module as missing after the deadline', function (): void {
    $cycle = $this->s->publish();
    $query = app(ComplianceQuery::class);
    $oversight = $this->s->actor($this->s->admin);

    // Nobody has done anything. The deadline (14 November) has passed.
    $summary = $query->summary($oversight, ['cycle_id' => $cycle->id]);
    $rows = collect($query->register($oversight, ['cycle_id' => $cycle->id])->items());

    expect($summary['counts']['expected'])->toBe(3)
        ->and($summary['counts']['overdue_unsubmitted'])->toBe(3)
        ->and($summary['counts']['not_started'])->toBe(3)
        ->and($summary['rates']['submission_rate'])->toBe(0.0)
        ->and($rows->pluck('timeliness')->unique()->all())->toBe(['overdue'])
        ->and($rows->firstWhere('organization.code', 'RPT-A')['days_overdue'])->toBe(6)
        // A saved draft is still not a submission.
        ->and($rows->pluck('first_submitted_at')->filter()->all())->toBe([]);

    $this->s->write($this->s->obligation($cycle, 'A'), $this->s->preparer('A'));
    $summary = $query->summary($oversight, ['cycle_id' => $cycle->id]);

    expect($summary['counts']['draft'])->toBe(1)
        ->and($summary['counts']['overdue_unsubmitted'])->toBe(3);
});

it('picks up an institution added to the schedule without duplicating the others', function (): void {
    $cycle = $this->s->publish();
    $admin = $this->s->actor($this->s->admin);

    app(ManageSchedules::class)->save($admin, $this->s->schedule->id, $this->s->schedule->only([
        'report_type_id', 'name_en', 'name_am', 'frequency', 'calendar_basis', 'fiscal_year_start_month', 'opens_offset_days', 'due_offset_days', 'due_time',
        'timezone', 'holiday_adjustment', 'review_sla_working_days', 'revision_days', 'late_policy', 'receiving_organization_id', 'is_active',
    ]) + ['starts_on' => '2026-10-11'], array_map(fn (string $k): array => ['organization_id' => $this->s->org[$k]->id], ['A', 'B', 'C', 'X']));
    $this->s->publish();

    expect(Obligation::query()->where('cycle_id', $cycle->id)->count())->toBe(4)
        ->and(Obligation::query()->where('cycle_id', $cycle->id)->where('organization_id', $this->s->org['A']->id)->count())->toBe(1);
});

it('keeps the names and structure of the day when the organization changes later', function (): void {
    $cycle = $this->s->publish();
    $obligation = $this->s->obligation($cycle, 'A');

    // The institution is renamed in the Core after the cycle was published.
    Organization::query()->whereKey($this->s->org['A']->id)->update(['name_en' => 'Trade and Industry Bureau', 'name_am' => 'ንግድና ኢንዱስትሪ ቢሮ']);

    $row = collect(app(ComplianceQuery::class)->register($this->s->actor($this->s->admin), ['cycle_id' => $cycle->id])->items())->firstWhere('id', $obligation->id);

    expect($row['organization']['name_en'])->toBe('Trade Bureau')
        ->and($row['organization']['name_am'])->toBe('ንግድ ቢሮ')
        ->and($obligation->fresh()->hierarchy_snapshot)->toHaveKeys(['version', 'ancestors']);

    // The next period carries the new name; the old period is untouched.
    ReportingScenario::clock('2026-12-05 09:00');
    $next = $this->s->publish('2026-11-20');

    expect($this->s->obligation($next, 'A')->organization_name_en)->toBe('Trade and Industry Bureau')
        ->and($obligation->fresh()->organization_name_en)->toBe('Trade Bureau');
});

it('records a change of responsible officer as history, without rewriting the past', function (): void {
    $cycle = $this->s->publish();
    $obligation = $this->s->obligation($cycle, 'A');
    $officer = $this->s->officer('A');
    $first = $this->s->preparer('A');
    $second = $this->s->preparer('A');
    $deadlines = app(ManageDeadlines::class);

    $deadlines->reassignResponsible($this->s->actor($officer), $obligation->id, $first->id, $officer->id, 'Initial assignment');
    $deadlines->reassignResponsible($this->s->actor($officer), $obligation->id, $second->id, $officer->id, 'The first preparer went on leave');

    $events = ObligationEvent::query()->where('obligation_id', $obligation->id)->where('event', 'obligation_reassigned')->orderBy('created_at')->get();

    expect($obligation->fresh()->preparer_name)->toBe($second->name)
        ->and($events)->toHaveCount(2)
        ->and($events[1]->data['before']['preparer_name'])->toBe($first->name)
        ->and($events[1]->data['reason'])->toBe('The first preparer went on leave')
        ->and(fn () => $deadlines->reassignResponsible($this->s->actor($this->s->officer('X')), $obligation->id, null, null, 'Not mine'))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $deadlines->reassignResponsible($this->s->actor($officer), $obligation->id, 999999, null, 'Nobody'))->toThrow(WorkflowViolation::class, 'unknown_user');
});

it('refuses a schedule that names an institution outside the actor\'s scope', function (): void {
    $scoped = $this->s->user(['reporting.view', 'reporting.schedules.publish'], ['A']);

    expect(fn () => app(ManageSchedules::class)->resolveTargets($this->s->actor($scoped), [['organization_id' => $this->s->org['B']->id]]))->toThrow(ReportingAccessDenied::class)
        ->and(app(ManageSchedules::class)->resolveTargets($this->s->actor($scoped), [['organization_id' => $this->s->org['A']->id]]))->toHaveCount(1);
});

it('needs a published template before a period can be published', function (): void {
    $bare = $this->s;
    $admin = $bare->actor($bare->admin);
    $type = app(ConfigureReportTypes::class)->saveType($admin, null, [
        'code' => 'BARE', 'name_en' => 'Bare', 'name_am' => 'ባዶ', 'frequency' => 'monthly', 'submission_mode' => 'either', 'confidentiality' => 'internal',
    ]);
    $schedule = app(ManageSchedules::class)->save($admin, null, [
        'report_type_id' => $type->id, 'name_en' => 'Bare', 'name_am' => 'ባዶ', 'frequency' => 'monthly', 'calendar_basis' => 'gregorian', 'fiscal_year_start_month' => 1,
        'opens_offset_days' => -7, 'due_offset_days' => 5, 'due_time' => '17:30', 'timezone' => 'Africa/Addis_Ababa', 'holiday_adjustment' => 'none',
        'review_sla_working_days' => 5, 'revision_days' => 5, 'late_policy' => 'accept', 'starts_on' => '2026-10-01',
    ], [['organization_id' => $bare->org['A']->id]]);

    expect(fn () => app(PublishCycles::class)->publishPeriod($admin, $schedule->id, '2026-10-15'))->toThrow(WorkflowViolation::class, 'no_published_template');
});
