<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Ports\MetricSource;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Support\MetricSources;
use Modules\Reporting\Application\Support\ReportingPaused;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\ConsolidateReports;
use Modules\Reporting\Application\UseCases\ScheduledWork;
use Modules\Reporting\Contracts\Events\ReportApprovedV1;
use Modules\Reporting\Contracts\Events\ReportingDeadlineMissedV1;
use Modules\Reporting\Contracts\Events\ReportSubmittedV1;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Infrastructure\Integration\ServiceMonitoringMetricSource;
use Modules\Reporting\Infrastructure\Notifications\ReportingNotification;
use Modules\Reporting\Infrastructure\Outbox\DatabaseOutbox;
use Modules\Reporting\Infrastructure\Persistence\Models\NotificationDelivery;
use Modules\Reporting\Infrastructure\Persistence\Models\OutboxMessage;
use Modules\Reporting\Infrastructure\Persistence\Models\SchedulerRun;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Modules\Reporting\Infrastructure\Persistence\OperationalHealth;
use Tests\Support\ReportingScenario;

/*
 * Reliable events (the transactional outbox and idempotent consumers), the
 * three reminder tracks, missed deadlines, the maintenance switch and
 * independence from optional modules.
 */

beforeEach(function (): void {
    Storage::fake('local');
    ReportingScenario::clock('2026-11-10 09:00');
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
    $this->obligation = $this->s->obligation($this->cycle, 'A');
    $this->bell = fn (User $user): int => $user->notifications()->count();
});

it('stores the event with the submission and relays it once', function (): void {
    $reviewer = $this->s->reviewer();
    $seen = [];
    Event::listen(ReportSubmittedV1::class, function (ReportSubmittedV1 $event) use (&$seen): void {
        $seen[] = $event;
    });

    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $message = OutboxMessage::query()->where('event_type', ReportSubmittedV1::NAME)->firstOrFail();

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->submissionId)->toBe($submission->id)
        ->and($seen[0]->isFirstSubmission)->toBeTrue()
        ->and($message->status)->toBe('dispatched')
        ->and($message->event_version)->toBe(1)
        // Identifiers and facts only: the narrative never travels in an event.
        ->and(json_encode($message->payload))->not->toContain('Text')
        ->and(array_keys($message->payload))->toBe(['eventId', 'occurredAt', 'obligationId', 'submissionId', 'versionNo', 'referenceNo', 'organizationId', 'reportTypeId', 'cycleId', 'isFirstSubmission', 'isLate'])
        ->and(($this->bell)($reviewer))->toBe(1);

    // A second relay finds nothing to do.
    expect(app(DatabaseOutbox::class)->relay())->toBe(['dispatched' => 0, 'failed' => 0])
        ->and($seen)->toHaveCount(1);
});

it('does not repeat a side effect when the same event is delivered again', function (): void {
    $reviewer = $this->s->reviewer();
    $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $message = OutboxMessage::query()->where('event_type', ReportSubmittedV1::NAME)->firstOrFail();

    // At-least-once delivery: the relay crashed after dispatching and before marking the row,
    // so the same event (same id) goes out a second and a third time.
    $message->forceFill(['status' => 'pending', 'dispatched_at' => null])->save();
    app(DatabaseOutbox::class)->relay();
    event(new ReportSubmittedV1(...$message->payload));

    expect(($this->bell)($reviewer))->toBe(1)
        ->and(NotificationDelivery::query()->where('track', 'workflow')->where('stage', 'submitted')->count())->toBe(1);
});

it('retries a failed relay with a delay and leaves it visible when it keeps failing', function (): void {
    $healthy = false;
    $handled = 0;
    Event::listen(ReportApprovedV1::class, function () use (&$healthy, &$handled): void {
        if (! $healthy) {
            throw new RuntimeException('Downstream is unavailable');
        }
        $handled++;
    });

    $this->s->approve($this->s->writeAndSubmit($this->obligation, $this->s->officer('A')));
    $message = OutboxMessage::query()->where('event_type', ReportApprovedV1::NAME)->firstOrFail();

    // The approval itself is committed; only the event is waiting.
    expect($this->obligation->fresh()->workflow_status->value)->toBe('approved')
        ->and($message->status)->toBe('pending')
        ->and($message->attempts)->toBe(1)
        ->and($message->last_error)->toContain('Downstream is unavailable')
        // Not yet due again: the next attempt waits.
        ->and(app(DatabaseOutbox::class)->relay())->toBe(['dispatched' => 0, 'failed' => 0]);

    $healthy = true;
    ReportingScenario::clock('2026-11-10 09:10');
    expect(app(DatabaseOutbox::class)->relay())->toBe(['dispatched' => 1, 'failed' => 0])
        ->and($handled)->toBe(1)
        ->and($message->fresh()->status)->toBe('dispatched');

    // A message that never gets through ends as "failed" and shows on the health report.
    $stuck = OutboxMessage::query()->create([
        'event_id' => (string) Str::uuid7(), 'event_type' => 'reporting.unknown_event', 'event_version' => 1, 'payload' => [],
        'occurred_at' => now('UTC'), 'status' => 'pending', 'attempts' => DatabaseOutbox::MAX_ATTEMPTS - 1, 'available_at' => now('UTC'),
    ]);
    app(DatabaseOutbox::class)->relay();

    expect($stuck->fresh()->status)->toBe('failed')
        ->and(app(OperationalHealth::class)->report()['outbox']['failed'])->toBe(1)
        ->and(app(OperationalHealth::class)->report()['healthy'])->toBeFalse();
});

it('stamps a missed deadline once and publishes its event once', function (): void {
    $events = [];
    Event::listen(ReportingDeadlineMissedV1::class, function (ReportingDeadlineMissedV1 $event) use (&$events): void {
        $events[] = $event->organizationId;
    });
    // A submits before the deadline; B and C do not.
    $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $work = app(ScheduledWork::class);

    expect($work->markMissedDeadlines())->toBe(0);

    ReportingScenario::clock('2026-11-14 17:31');
    expect($work->markMissedDeadlines())->toBe(2)
        ->and($work->markMissedDeadlines())->toBe(0);
    app(DatabaseOutbox::class)->relay();
    sort($events);
    $expected = [$this->s->org['B']->id, $this->s->org['C']->id];
    sort($expected);

    expect($events)->toBe($expected)
        ->and(OutboxMessage::query()->where('event_type', ReportingDeadlineMissedV1::NAME)->count())->toBe(2)
        ->and($this->obligation->fresh()->deadline_missed_at)->toBeNull();
});

it('sends each submission reminder stage once and stops when the report is submitted', function (): void {
    $officerA = $this->s->officer('A');
    $officerB = $this->s->officer('B');
    $work = app(ScheduledWork::class);

    // 10 November: four days before the deadline. The 7-day stage is the one in force.
    expect($work->sendReminders()['submission'])->toBe(2)
        // The same day again: nothing new.
        ->and($work->sendReminders()['submission'])->toBe(0)
        ->and(($this->bell)($officerA))->toBe(1);

    // A submits. From now on A's reminders stop; B's go on.
    $this->s->writeAndSubmit($this->obligation, $officerA);
    $afterSubmission = ($this->bell)($officerA);

    ReportingScenario::clock('2026-11-12 09:00');
    expect($work->sendReminders()['submission'])->toBe(1);
    ReportingScenario::clock('2026-11-14 09:00');
    expect($work->sendReminders()['submission'])->toBe(1);
    ReportingScenario::clock('2026-11-15 09:00');
    expect($work->sendReminders()['submission'])->toBe(1);

    // Three working days after the deadline (Saturday 14th 17:30 → Wednesday 18th 17:30) the escalation goes out once.
    ReportingScenario::clock('2026-11-18 09:00');
    expect($work->sendReminders()['submission'])->toBe(0);
    ReportingScenario::clock('2026-11-20 09:00');
    expect($work->sendReminders()['submission'])->toBe(1)
        ->and($work->sendReminders()['submission'])->toBe(0);

    $stages = NotificationDelivery::query()->where('track', 'submission')->where('recipient_user_id', $officerB->id)->orderBy('created_at')->pluck('stage')->all();
    expect($stages)->toBe(['before_7', 'before_3', 'due', 'overdue', 'escalated'])
        ->and(($this->bell)($officerA))->toBe($afterSubmission)
        ->and(NotificationDelivery::query()->where('track', 'submission')->where('recipient_user_id', $officerA->id)->count())->toBe(1);

    // The notice is worded in the reader's language when shown.
    $notice = $officerB->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'submission_overdue');
    $am = ReportingNotification::render($notice->data, 'am');
    $en = ReportingNotification::render($notice->data, 'en');
    expect($am['title'])->toContain('ሪፖርት ጊዜው አልፏል')->toContain('ወርሃዊ የሥራ አፈጻጸም ሪፖርት')
        ->and($en['title'])->toBe('Report overdue: Monthly performance report')
        ->and($en['message'])->toContain('Health Bureau')->toContain('2026-11-14 17:30 EAT');
});

it('recovers after missed runs with one notice, not the backlog', function (): void {
    $officer = $this->s->officer('B');

    // The scheduler did not run at all between publication and five days after the deadline.
    ReportingScenario::clock('2026-11-17 09:00');
    app(ScheduledWork::class)->sendReminders();

    expect(NotificationDelivery::query()->where('track', 'submission')->where('recipient_user_id', $officer->id)->pluck('stage')->all())->toBe(['overdue']);
});

it('tracks reviewer and revision reminders separately from submission reminders', function (): void {
    $officer = $this->s->officer('A');
    $reviewer = $this->s->reviewer();
    $submission = $this->s->writeAndSubmit($this->obligation, $officer);
    $work = app(ScheduledWork::class);
    $submittedNotices = ($this->bell)($reviewer);

    // The review is due five working days after submission (17 November). On the 18th it is overdue.
    ReportingScenario::clock('2026-11-18 09:00');
    $sent = $work->sendReminders();
    expect($sent['review'])->toBe(1)
        ->and(($this->bell)($reviewer))->toBe($submittedNotices + 1)
        ->and(NotificationDelivery::query()->where('track', 'review')->pluck('stage')->all())->toBe(['overdue'])
        // Nothing goes to the submitter for the reviewer's delay.
        ->and(NotificationDelivery::query()->whereIn('track', ['submission', 'revision'])->where('recipient_user_id', $officer->id)->count())->toBe(0);

    // Returned: the review reminders stop and the revision track starts, with its own deadline.
    $this->s->decide($submission, $reviewer, ReviewDecision::Return, 'Please add the annex.');
    ReportingScenario::clock('2026-11-22 09:30');
    $sent = $work->sendReminders();

    expect($sent['review'])->toBe(0)
        ->and($sent['revision'])->toBe(1)
        ->and(NotificationDelivery::query()->where('track', 'revision')->where('recipient_user_id', $officer->id)->value('stage'))->toBe('due');

    // Resubmitted: the revision reminders stop.
    $this->s->write($this->obligation->fresh(), $officer, 'Revised');
    $this->s->submit($this->obligation->fresh(), $officer);
    ReportingScenario::clock('2026-11-24 09:00');
    expect($work->sendReminders()['revision'])->toBe(0);
});

it('pauses writes and scheduled work with the maintenance switch and keeps the data readable', function (): void {
    $officer = $this->s->officer('A');
    $this->s->write($this->obligation, $officer);
    $settings = app(ReportingSettings::class);
    $settings->put('maintenance', true, $this->s->admin->id);
    app()->forgetScopedInstances();

    expect(fn () => $this->s->write($this->obligation, $officer, 'More'))->toThrow(ReportingPaused::class)
        ->and(fn () => $this->s->submit($this->obligation, $officer))->toThrow(ReportingPaused::class)
        ->and(fn () => $this->s->publish('2026-11-20'))->toThrow(ReportingPaused::class);

    $this->artisan('institutional-reporting:run')->assertSuccessful();
    expect(SchedulerRun::query()->where('status', 'paused')->count())->toBe(5)
        ->and(Submission::query()->count())->toBe(0)
        // Reading still works.
        ->and(app(ComplianceQuery::class)->summary($this->s->actor($this->s->admin), [])['counts']['expected'])->toBe(3)
        ->and(app(OperationalHealth::class)->report()['paused'])->toBeTrue();

    // Switched back on: the draft is as it was left and work resumes.
    app(ReportingSettings::class)->put('maintenance', false, $this->s->admin->id);
    app()->forgetScopedInstances();
    $submission = $this->s->submit($this->obligation, $officer);
    $this->artisan('institutional-reporting:run')->assertSuccessful();

    expect($submission->content['executive_summary'])->toContain('Text')
        ->and(SchedulerRun::query()->where('status', 'completed')->count())->toBe(5)
        ->and(app(OperationalHealth::class)->report()['paused'])->toBeFalse();
});

it('works with every optional business module switched off or failing', function (): void {
    // Transport, service monitoring and letters are disabled; the one indicator source throws.
    config(['features.transport' => false, 'features.service_monitoring' => false, 'features.letters' => false]);
    app()->bind(MetricSources::class, fn () => new MetricSources([new class implements MetricSource
    {
        public function key(): string
        {
            return 'broken';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function capture(array $organizationIds, string $periodStart, string $periodEnd): array
        {
            throw new RuntimeException('The other module is down');
        }
    }]));

    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $this->s->approve($submission);
    $oversight = $this->s->actor($this->s->admin);
    $consolidate = app(ConsolidateReports::class);
    $compiled = $consolidate->compile($oversight, $consolidate->start($oversight, $this->cycle->id, $this->s->org['HQ']->id, 'Consolidated')->id);

    expect($this->obligation->fresh()->workflow_status->value)->toBe('approved')
        ->and($compiled->status)->toBe('compiled')
        ->and(app(MetricSources::class)->capture([], '2026-10-11', '2026-11-09'))->toBe([])
        // The shipped source reports itself unavailable instead of failing.
        ->and((new ServiceMonitoringMetricSource(app()))->isAvailable())->toBeFalse();
});
