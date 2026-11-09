<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\ServiceMonitoring\Application\Notifications\ActionItemOverdueNotification;
use Modules\ServiceMonitoring\Application\Notifications\ClarificationRepliedNotification;
use Modules\ServiceMonitoring\Application\Notifications\DueInThreeDaysNotification;
use Modules\ServiceMonitoring\Application\Notifications\DueTodayNotification;
use Modules\ServiceMonitoring\Application\Notifications\MissingAfterDueNotification;
use Modules\ServiceMonitoring\Application\Notifications\PeriodOpenedNotification;
use Modules\ServiceMonitoring\Application\Services\ReminderService;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\ReminderLog;
use Modules\ServiceMonitoring\Domain\Models\SupportAssignment;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Reminders (D23) and support (D22): clarifications, visits, findings,
 * action items and overdue detection.
 */

final class SmFakeSms implements SmsGateway
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $configured) {}

    public function send(string $phoneNumber, string $message): bool
    {
        $this->sent[] = [$phoneNumber, $message];

        return true;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
    $this->head = $this->s->head('wA1');
    $this->reviewer = $this->s->reviewer('subA');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('sends each reminder on its day, once', function (): void {
    $run = fn (string $date) => tap(app(ReminderService::class), fn () => $this->s->clock($date))->run();

    expect($run('2026-09-11')['period_opened'])->toBe(6);
    Notification::assertSentTo($this->head, PeriodOpenedNotification::class);
    expect($run('2026-09-11')['period_opened'])->toBe(0);

    // Three days before the deadline, only those not yet submitted hear about it.
    $this->s->at(1);
    $this->s->accept($this->s->submit($this->s->complete($this->s->start('wA2'))));
    $sent = $run('2026-10-17');
    expect($sent['due_in_3_days'])->toBe(5);
    Notification::assertSentTo($this->head, DueInThreeDaysNotification::class);
    Notification::assertNotSentTo($this->s->head('wA2'), DueInThreeDaysNotification::class);

    expect($run('2026-10-20')['due_today'])->toBe(5);
    Notification::assertSentTo($this->head, DueTodayNotification::class);

    // The day after: missing reports, to the submitters and the reviewer.
    expect($run('2026-10-21')['missing_after_due'])->toBe(5);
    Notification::assertSentTo($this->reviewer, MissingAfterDueNotification::class);
    expect($run('2026-10-21')['missing_after_due'])->toBe(0)
        ->and(ReminderLog::query()->where('type', 'missing_after_due')->count())->toBe(5);
});

it('uses SMS only when the gateway is configured and the user has a phone', function (): void {
    $this->head->forceFill(['phone_number' => '+251911000000'])->save();

    $off = new SmFakeSms(false);
    app()->instance(SmsGateway::class, $off);
    $this->s->clock('2026-10-20');
    app(ReminderService::class)->run();
    expect($off->sent)->toBe([]);

    $on = new SmFakeSms(true);
    app()->instance(SmsGateway::class, $on);
    $this->s->clock('2026-10-21');
    app(ReminderService::class)->run();
    expect($on->sent)->not->toBeEmpty()->and($on->sent[0][0])->toBe('+251911000000');
});

it('runs the scheduled command as a job', function (): void {
    $this->s->clock('2026-09-11');
    $this->artisan('service-monitoring:send-reminders', ['--sync' => true])->assertSuccessful();
    expect(ReminderLog::query()->where('type', 'period_opened')->count())->toBe(6);
});

it('takes a clarification from open to answered to closed, inside scope only', function (): void {
    $support = $this->s->user(ServiceMonitoringRoles::SUPPORT_OFFICER_PERMISSIONS, ['center']);
    SupportAssignment::query()->create(['participant_id' => $this->s->p['wA1']->id, 'user_id' => $support->id]);
    $this->s->forget();

    $this->actingAs($this->s->head('wA2'))->post(route('service-monitoring.clarifications.store'), ['participant_id' => $this->s->p['wA1']->id, 'subject' => 'Day-care?', 'body' => 'How do we count it?'])->assertForbidden();
    $this->actingAs($this->head)->post(route('service-monitoring.clarifications.store'), ['participant_id' => $this->s->p['wA1']->id, 'subject' => 'Day-care?', 'body' => 'How do we count a day-care opened last year?', 'section' => 2])->assertRedirect();
    $clarification = Clarification::query()->sole();
    expect($clarification->status->value)->toBe('open');

    $this->actingAs($this->s->head('wA2'))->get(route('service-monitoring.clarifications.show', $clarification))->assertForbidden();
    $this->actingAs($support)->post(route('service-monitoring.clarifications.reply', $clarification), ['body' => 'Only facilities organized this fiscal year count.'])->assertSessionHasNoErrors();
    expect($clarification->fresh()->status->value)->toBe('answered');
    Notification::assertSentTo($this->head, ClarificationRepliedNotification::class);

    $this->actingAs($this->head)->post(route('service-monitoring.clarifications.close', $clarification))->assertSessionHasNoErrors();
    expect($clarification->fresh()->status->value)->toBe('closed');
    $this->actingAs($support)->post(route('service-monitoring.clarifications.reply', $clarification), ['body' => 'One more thing.'])->assertSessionHasErrors('body');
});

it('records a visit with findings and action items, and flags overdue items', function (): void {
    $support = $this->s->user(ServiceMonitoringRoles::SUPPORT_OFFICER_PERMISSIONS, ['center']);
    SupportAssignment::query()->create(['participant_id' => $this->s->p['wA1']->id, 'user_id' => $support->id]);
    $this->s->forget();
    $this->s->clock('2026-09-20');

    $this->actingAs($this->head)->post(route('service-monitoring.visits.store'), ['participant_id' => $this->s->p['wA1']->id, 'visited_on' => '2026-09-21', 'purpose' => 'Check the waiting area.'])->assertForbidden();
    $this->actingAs($support)->post(route('service-monitoring.visits.store'), ['participant_id' => $this->s->p['wA1']->id, 'visited_on' => '2026-09-21', 'purpose' => 'Check the waiting area.'])->assertRedirect();
    $visit = MonitoringVisit::query()->sole();
    expect($visit->members()->pluck('users.id')->all())->toBe([$support->id]);

    $this->actingAs($support)->post(route('service-monitoring.visits.findings.store', $visit), ['section' => 2, 'body' => 'No directional signage at the entrance.', 'severity' => 'high'])->assertSessionHasNoErrors();
    $this->actingAs($support)->post(route('service-monitoring.visits.action-items.store', $visit), [
        'title' => 'Install directional signage', 'finding_id' => $visit->findings()->value('id'), 'owner_organization_id' => $this->s->org['wA1']->id, 'due_on' => '2026-09-30',
    ])->assertSessionHasNoErrors();
    $item = ActionItem::query()->sole();

    $this->s->clock('2026-10-01');
    expect(ActionItem::query()->overdue('2026-10-01')->count())->toBe(1);
    app(ReminderService::class)->run();
    Notification::assertSentTo($support, ActionItemOverdueNotification::class);

    // The owning institution reports progress; done needs a note.
    $this->actingAs($this->head)->put(route('service-monitoring.action-items.update', $item), ['status' => 'done'])->assertSessionHasErrors('completion_note');
    $this->actingAs($this->head)->put(route('service-monitoring.action-items.update', $item), ['status' => 'done', 'completion_note' => 'Signs installed at both gates.'])->assertSessionHasNoErrors();
    expect($item->fresh()->status->value)->toBe('done')->and(ActionItem::query()->overdue('2026-10-01')->count())->toBe(0);
    $this->actingAs($this->s->head('wA2'))->put(route('service-monitoring.action-items.update', $item), ['status' => 'open'])->assertForbidden();
});
