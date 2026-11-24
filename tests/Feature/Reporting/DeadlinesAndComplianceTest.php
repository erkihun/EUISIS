<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\OrganizationSummary;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\UseCases\ConsolidateReports;
use Modules\Reporting\Application\UseCases\ManageDeadlines;
use Modules\Reporting\Application\UseCases\ManageFollowUps;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Contracts\ReportingCompliance;
use Modules\Reporting\Domain\Compliance\ComplianceTally;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Integration\CoreOrganizationDirectory;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\ConsolidationSource;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Tests\Support\ReportingScenario;

/*
 * Extensions, exemptions and historical fairness; the compliance figures and
 * their reconciliation with the register; follow-up actions; consolidation.
 */

beforeEach(function (): void {
    Storage::fake('local');
    ReportingScenario::clock('2026-11-10 09:00');
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
    $this->query = app(ComplianceQuery::class);
    $this->oversight = $this->s->actor($this->s->admin);
    $this->row = fn (string $code, array $filters = []): array => collect($this->query->register($this->oversight, ['cycle_id' => $this->cycle->id, ...$filters])->items())->firstWhere('organization.code', $code);
});

function due(string $local): DateTimeImmutable
{
    return CarbonImmutable::parse($local, 'Africa/Addis_Ababa')->utc()->toDateTimeImmutable();
}

it('does not move the deadline when an extension is only requested', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    $officer = $this->s->officer('A');
    $deadlines = app(ManageDeadlines::class);

    $extension = $deadlines->requestExtension($this->s->actor($officer), $obligation->id, due('2026-11-18 17:30'), 'The finance system was down for a week.');

    expect($obligation->fresh()->effective_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00')
        ->and($extension->status)->toBe('pending')
        // The requester cannot grant it; neither can someone outside the receiving side.
        ->and(fn () => $deadlines->decideExtension($this->s->actor($officer), $extension->id, true, 'Fine'))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $deadlines->decideExtension($this->s->actor($this->s->approver('X')), $extension->id, true, 'Fine'))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $deadlines->requestExtension($this->s->actor($officer), $obligation->id, due('2026-11-19 17:30'), 'Again'))->toThrow(WorkflowViolation::class, 'extension_already_pending')
        ->and(fn () => $deadlines->requestExtension($this->s->actor($this->s->officer('B')), $obligation->id, due('2026-11-19 17:30'), 'Not mine'))->toThrow(ReportingAccessDenied::class);

    // Rejected: still nothing changes.
    $deadlines->decideExtension($this->s->actor($this->s->approver()), $extension->id, false, 'The system was restored on the 9th.');
    expect($obligation->fresh()->effective_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00');
});

it('keeps the original deadline and original compliance when an extension is approved', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    $officer = $this->s->officer('A');
    $deadlines = app(ManageDeadlines::class);

    $extension = $deadlines->requestExtension($this->s->actor($officer), $obligation->id, due('2026-11-18 17:30'), 'Flood damage to the office.');
    $deadlines->decideExtension($this->s->actor($this->s->approver()), $extension->id, true, 'Confirmed by the sub-city.');

    // Submitted on the 16th: after the original deadline, before the extended one.
    ReportingScenario::clock('2026-11-16 10:00');
    $submission = $this->s->writeAndSubmit($obligation, $officer);
    ReportingScenario::clock('2026-11-20 09:00');
    $row = ($this->row)('RPT-A');
    $summary = $this->query->summary($this->oversight, ['cycle_id' => $this->cycle->id]);

    expect($obligation->fresh()->original_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00')
        ->and($obligation->fresh()->effective_due_at->toIso8601String())->toBe('2026-11-18T14:30:00+00:00')
        ->and($extension->fresh()->is_retrospective)->toBeFalse()
        ->and($submission->is_late)->toBeFalse()
        ->and($row['timeliness'])->toBe('on_time')
        ->and($row['timeliness_original'])->toBe('late')
        ->and($row['deadline_extended'])->toBeTrue()
        ->and($summary['counts']['submitted_on_time'])->toBe(1)
        ->and($summary['counts']['on_time_by_original_deadline'])->toBe(0);
});

it('labels an extension granted after the deadline as retrospective and keeps the evidence of lateness', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    $officer = $this->s->officer('A');
    $deadlines = app(ManageDeadlines::class);

    // The deadline passes; the scheduled task stamps it missed.
    ReportingScenario::clock('2026-11-15 09:00');
    $this->artisan('institutional-reporting:run', ['task' => 'deadlines'])->assertSuccessful();
    expect($obligation->fresh()->deadline_missed_at)->not->toBeNull();

    $extension = $deadlines->requestExtension($this->s->actor($officer), $obligation->id, due('2026-11-19 17:30'), 'The head of the institution was hospitalised.');
    $deadlines->decideExtension($this->s->actor($this->s->approver()), $extension->id, true, 'Accepted.');
    $fresh = $obligation->fresh();

    expect($extension->fresh()->is_retrospective)->toBeTrue()
        ->and($extension->fresh()->previous_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00')
        // Never silently erased.
        ->and($fresh->deadline_missed_at)->not->toBeNull()
        ->and($fresh->original_due_at->toIso8601String())->toBe('2026-11-14T14:30:00+00:00');

    // Seen as of the 15th, before the grant, the report was overdue; today it is not yet due.
    expect(($this->row)('RPT-A', ['as_of' => '2026-11-14'])['timeliness'])->toBe('overdue')
        ->and(($this->row)('RPT-A')['timeliness'])->toBe('not_due');
});

it('takes exempted and cancelled obligations out of the denominators, transparently', function (): void {
    $deadlines = app(ManageDeadlines::class);
    $approver = $this->s->approver();
    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $this->s->officer('A'));

    expect(fn () => $deadlines->setApplicability($this->s->actor($approver), $this->s->obligation($this->cycle, 'B')->id, Applicability::Exempted, ''))->toThrow(WorkflowViolation::class, 'reason_required')
        ->and(fn () => $deadlines->setApplicability($this->s->actor($this->s->officer('B')), $this->s->obligation($this->cycle, 'B')->id, Applicability::Exempted, 'Please'))->toThrow(ReportingAccessDenied::class);

    ReportingScenario::clock('2026-11-17 09:00');
    $deadlines->setApplicability($this->s->actor($approver), $this->s->obligation($this->cycle, 'B')->id, Applicability::Exempted, 'The bureau was established this month.');
    ReportingScenario::clock('2026-11-20 09:00');
    $summary = $this->query->summary($this->oversight, ['cycle_id' => $this->cycle->id]);

    // Three expected; one exempted; of the two that remain due, one submitted on time.
    expect($summary['counts']['expected'])->toBe(3)
        ->and($summary['counts']['exempted'])->toBe(1)
        ->and($summary['counts']['applicable'])->toBe(2)
        ->and($summary['counts']['due_required'])->toBe(2)
        ->and($summary['rates']['submission_rate'])->toBe(0.5)
        ->and(($this->row)('RPT-B')['applicability'])->toBe('exempted')
        ->and(($this->row)('RPT-B')['timeliness'])->toBeNull();

    // As of the 16th the exemption did not exist yet: three were due and one had submitted.
    $then = $this->query->summary($this->oversight, ['cycle_id' => $this->cycle->id, 'as_of' => '2026-11-16']);
    expect($then['counts']['due_required'])->toBe(3)
        ->and($then['counts']['exempted'])->toBe(0)
        ->and(round($then['rates']['submission_rate'], 4))->toBe(0.3333);

    // A reinstatement is a new dated decision, not an edit of the old one.
    $deadlines->setApplicability($this->s->actor($approver), $this->s->obligation($this->cycle, 'B')->id, Applicability::Required, 'Exempted in error.');
    expect($this->query->summary($this->oversight, ['cycle_id' => $this->cycle->id])['counts']['due_required'])->toBe(3);
});

it('reconciles the dashboard with the register and the export, filter by filter', function (): void {
    $officerA = $this->s->officer('A');
    $this->s->approve($this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $officerA));
    ReportingScenario::clock('2026-11-16 10:00');
    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'B'), $this->s->officer('B'));
    // C never reports. A second period, not yet due, is also published.
    ReportingScenario::clock('2026-12-04 09:00');
    $next = $this->s->publish('2026-11-20');
    $this->s->writeAndSubmit($this->s->obligation($next, 'A'), $officerA);

    $all = $this->query->summary($this->oversight, []);
    $rows = [];
    $this->query->eachRow($this->oversight, [], function (array $row) use (&$rows): void {
        $rows[] = $row;
    });
    $count = fn (callable $where): int => count(array_filter($rows, $where));
    $c = $all['counts'];

    expect($c['expected'])->toBe(6)->toBe(count($rows))->toBe($this->query->register($this->oversight, [])->total())
        ->and($c['submitted_on_time'])->toBe($count(fn ($r) => $r['timeliness'] === 'on_time'))->toBe(1)
        ->and($c['submitted_late'])->toBe($count(fn ($r) => $r['timeliness'] === 'late'))->toBe(1)
        ->and($c['overdue_unsubmitted'])->toBe($count(fn ($r) => $r['timeliness'] === 'overdue'))->toBe(1)
        ->and($c['early_submissions'])->toBe($count(fn ($r) => $r['timeliness'] === 'early'))->toBe(1)
        ->and($c['not_yet_due'])->toBe($count(fn ($r) => in_array($r['timeliness'], ['early', 'not_due'], true)))->toBe(3)
        ->and($c['approved'])->toBe($count(fn ($r) => $r['workflow'] === 'approved'))->toBe(1)
        // Rates: 3 due-required, 2 submitted, 1 on time; 3 ever submitted, 1 approved.
        ->and(round($all['rates']['submission_rate'], 4))->toBe(0.6667)
        ->and(round($all['rates']['on_time_rate'], 4))->toBe(0.3333)
        ->and(round($all['rates']['approval_rate'], 4))->toBe(0.3333);

    // Each timeliness filter returns exactly the obligations its count names.
    foreach (['on_time' => 'submitted_on_time', 'late' => 'submitted_late', 'overdue' => 'overdue_unsubmitted', 'early' => 'early_submissions'] as $filter => $key) {
        expect($this->query->register($this->oversight, ['timeliness' => $filter])->total())->toBe($c[$key]);
    }
    // Partitions add up; overlapping categories are not presented as a total.
    foreach (['expected', 'applicable', 'due_required'] as $total) {
        expect(array_sum(array_map(fn (string $part): int => $c[$part], ComplianceTally::partitions()[$total])))->toBe($c[$total]);
    }

    // The trend, the matrix and the public contract agree with the same figures.
    $trend = $this->query->trend($this->oversight, []);
    $matrix = $this->query->matrix($this->oversight, ['report_type_id' => $this->s->type->id]);
    $contract = app(ReportingCompliance::class)->summary([$this->s->org['A']->id, $this->s->org['B']->id]);

    expect($trend)->toHaveCount(1)
        ->and($trend[0]['due_required'])->toBe(3)
        ->and(round($trend[0]['submission_rate'], 4))->toBe(0.6667)
        ->and($matrix['cycles'])->toHaveCount(2)
        ->and($matrix['institutions']->total())->toBe(3)
        ->and(collect($matrix['institutions']->items())->firstWhere('code', 'RPT-C')['cells'][$this->cycle->id]['timeliness'])->toBe('overdue')
        ->and($contract->counts['expected'])->toBe(4)
        ->and($contract->submissionRate)->toBe(1.0);
});

it('gives an institution\'s manager only its own figures', function (): void {
    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $this->s->officer('A'));
    ReportingScenario::clock('2026-11-20 09:00');

    $manager = $this->s->actor($this->s->officer('B'));
    $receiving = $this->s->actor($this->s->reviewer());
    $outsider = $this->s->actor($this->s->user(ReportingRoles::ALL, ['X']));
    $nobody = $this->s->actor($this->s->user(['dashboard.view'], ['A']));

    expect($this->query->summary($manager, [])['counts']['expected'])->toBe(1)
        ->and($this->query->register($manager, [])->total())->toBe(1)
        ->and($this->query->register($manager, ['organization_id' => $this->s->org['A']->id])->total())->toBe(0)
        // The receiving bureau sees everything sent to it.
        ->and($this->query->summary($receiving, [])['counts']['expected'])->toBe(3)
        // Every permission, but no reach: nothing; no permission: nothing.
        ->and($this->query->summary($outsider, [])['counts']['expected'])->toBe(0)
        ->and($this->query->register($outsider, [])->total())->toBe(0)
        ->and($this->query->summary($nobody, [])['counts']['expected'])->toBe(0)
        ->and(app(WorkQueues::class)->reviewInbox($outsider)->count())->toBe(0)
        ->and(app(WorkQueues::class)->reviewInbox($receiving)->count())->toBe(1)
        ->and(app(WorkQueues::class)->assignments($manager)->count())->toBe(1);
});

it('gives follow-up actions their own lifecycle, apart from submission compliance', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    $officer = $this->s->officer('A');
    $submission = $this->s->writeAndSubmit($obligation, $officer);
    $reviewer = $this->s->reviewer();
    $followUps = app(ManageFollowUps::class);

    expect(fn () => $followUps->create($this->s->actor($officer), $obligation->id, ['title' => 'Mine', 'description' => 'No', 'due_at' => due('2026-12-01 17:30')]))->toThrow(ReportingAccessDenied::class);
    $action = $followUps->create($this->s->actor($reviewer), $obligation->id, ['title' => 'Fill the two vacant posts', 'description' => 'Reported as a challenge.', 'owner_user_id' => $officer->id, 'priority' => 'high', 'due_at' => due('2026-12-01 17:30')]);

    $followUps->update($this->s->actor($officer), $action->id, ['progress' => 50, 'status' => 'in_progress', 'note' => 'One post filled.']);
    // Only the receiving side accepts an action as closed.
    expect(fn () => $followUps->update($this->s->actor($officer), $action->id, ['progress' => 100, 'status' => 'closed', 'note' => 'Done.']))->toThrow(ReportingAccessDenied::class);
    $followUps->update($this->s->actor($reviewer), $action->id, ['progress' => 100, 'status' => 'closed', 'note' => 'Both posts filled; verified.']);

    $before = $obligation->fresh()->only(['workflow_status', 'first_submitted_at', 'effective_due_at']);
    expect($action->fresh()->status)->toBe('closed')
        ->and($action->fresh()->updates()->count())->toBe(2)
        ->and($obligation->fresh()->first_submission_id)->toBe($submission->id)
        ->and($obligation->fresh()->only(['workflow_status', 'first_submitted_at', 'effective_due_at']))->toEqual($before)
        ->and(fn () => $followUps->update($this->s->actor($reviewer), $action->id, ['progress' => 100, 'status' => 'open', 'note' => 'Again']))->toThrow(WorkflowViolation::class, 'follow_up_closed');
});

it('compiles approved reports and names every institution that is missing', function (): void {
    // A, B and C sit under the receiving bureau for this test: the directory port is the seam.
    $real = app(CoreOrganizationDirectory::class);
    $ids = array_map(fn (string $k): string => $this->s->org[$k]->id, ['HQ', 'A', 'B', 'C']);
    app()->bind(OrganizationDirectory::class, fn () => new class($real, $ids) implements OrganizationDirectory
    {
        public function __construct(private CoreOrganizationDirectory $inner, private array $subtree) {}

        public function find(string $organizationId): ?OrganizationSummary
        {
            return $this->inner->find($organizationId);
        }

        public function findMany(array $organizationIds): array
        {
            return $this->inner->findMany($organizationIds);
        }

        public function hierarchySnapshot(string $organizationId): array
        {
            return $this->inner->hierarchySnapshot($organizationId);
        }

        public function subtreeIds(string $organizationId): array
        {
            return $this->subtree;
        }

        public function search(ActorContext $actor, string $term, int $limit = 20): array
        {
            return $this->inner->search($actor, $term, $limit);
        }

        public function page(ActorContext $actor, int $page = 1, int $perPage = 50): array
        {
            return $this->inner->page($actor, $page, $perPage);
        }

        public function units(string $organizationId): array
        {
            return $this->inner->units($organizationId);
        }
    });

    $this->s->approve($this->s->writeAndSubmit($this->s->obligation($this->cycle, 'A'), $this->s->officer('A')));
    $pending = $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'B'), $this->s->officer('B'));
    ReportingScenario::clock('2026-11-20 09:00');

    $consolidate = app(ConsolidateReports::class);
    expect(fn () => $consolidate->start($this->s->actor($this->s->officer('A')), $this->cycle->id, $this->s->org['HQ']->id, 'Mine'))->toThrow(ReportingAccessDenied::class);

    $draft = $consolidate->start($this->oversight, $this->cycle->id, $this->s->org['HQ']->id, 'Consolidated monthly report');
    $consolidate->saveSummary($this->oversight, $draft->id, 'Consolidated monthly report', '<p>የወሩ አጠቃላይ ማጠቃለያ</p>', 0);
    $compiled = $consolidate->compile($this->oversight, $draft->id);
    $missing = collect($compiled->missing)->keyBy('code');

    expect($compiled->indicators)->toMatchArray(['expected' => 3, 'included' => 1, 'missing' => 2, 'submitted_on_time' => 2])
        ->and(ConsolidationSource::query()->where('consolidation_id', $compiled->id)->count())->toBe(1)
        ->and($missing['RPT-B']['status'])->toBe('submitted')
        ->and($missing['RPT-C']['status'])->toBe('not_submitted')
        ->and(Artifact::query()->where('owner_type', 'consolidation')->where('owner_id', $compiled->id)->value('status'))->toBe('completed');

    // Compiled again after B is approved: B is counted once, with its approved version.
    $this->s->approve($pending);
    $again = $consolidate->compile($this->oversight, $draft->id);
    $source = ConsolidationSource::query()->where('consolidation_id', $again->id)->where('organization_id', $this->s->org['B']->id)->firstOrFail();

    expect($again->indicators['included'])->toBe(2)
        ->and(ConsolidationSource::query()->where('consolidation_id', $again->id)->count())->toBe(2)
        ->and($source->submission_id)->toBe($pending->id)
        ->and($source->version_no)->toBe(1)
        ->and(collect($again->missing)->pluck('code')->all())->toBe(['RPT-C']);
});

it('counts a pending extension request for the receiving side only', function (): void {
    $obligation = $this->s->obligation($this->cycle, 'A');
    app(ManageDeadlines::class)->requestExtension($this->s->actor($this->s->officer('A')), $obligation->id, due('2026-11-18 17:30'), 'Reason');
    $queues = app(WorkQueues::class);

    expect($queues->pendingExtensions($this->s->actor($this->s->approver()))->count())->toBe(1)
        ->and($queues->pendingExtensions($this->s->actor($this->s->approver('X')))->count())->toBe(0)
        ->and($queues->pendingExtensions($this->s->actor($this->s->reviewer()))->count())->toBe(0)
        ->and(Extension::query()->count())->toBe(1);
});
