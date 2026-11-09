<?php

declare(strict_types=1);

use App\Actions\IdCards\RevokeCardAction;
use App\Actions\Positions\ArchivePositionAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Integration\IntegrationEvent;
use App\Services\Integration\Events\EventCatalog;
use App\Services\Integration\Events\IntegrationEventRecorder;
use App\Services\Integration\Events\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntegrationScenario;

/**
 * Transactional outbox: domain change and event commit together, events are
 * minimised, ordered per aggregate, and only real changes emit.
 */
beforeEach(function (): void {
    $this->s = IntegrationScenario::build();
});

it('writes no event when the domain transaction rolls back', function (): void {
    $before = IntegrationEvent::query()->count();

    try {
        DB::transaction(function (): void {
            Employee::query()->create(['employee_number' => 'EVT-ROLLBACK', 'first_name' => 'X', 'last_name' => 'Y', 'full_name' => 'X Y', 'status' => 'active']);
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(IntegrationEvent::query()->count())->toBe($before)
        ->and(Employee::query()->where('employee_number', 'EVT-ROLLBACK')->exists())->toBeFalse();
});

it('commits the event with the change', function (): void {
    $employee = Employee::query()->create(['employee_number' => 'EVT-NEW', 'first_name' => 'X', 'last_name' => 'Y', 'full_name' => 'X Y', 'status' => 'active']);

    $event = IntegrationEvent::query()->where('aggregate_uuid', $employee->id)->sole();
    expect($event->event_type)->toBe('employee.created')
        ->and($event->status)->toBe('pending')
        ->and($event->sequence)->toBe(1)
        ->and(Str::isUuid($event->event_id))->toBeTrue();
});

it('records employee updates as minimised change categories, never personal data', function (): void {
    $employee = $this->s->woredaEmployee;
    $since = IntegrationEvent::query()->max('id');

    $employee->update(['first_name' => 'Hanna', 'phone' => '0900000000']);
    $employee->update(['phone' => '0911111111', 'national_id' => '1111222233334444']);

    $events = IntegrationEvent::query()->where('id', '>', $since)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe('employee.updated')
        ->and($events[0]->payload['changed_fields'])->toBe(['name']);

    $raw = json_encode($events[0]->payload);
    foreach (['0900000000', '0911111111', '1111222233334444', '0911223344', 'Hanna', 'phone', 'national_id', 'date_of_birth'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('marks a separation as a tombstone status change', function (): void {
    $this->s->woredaEmployee->update(['status' => EmployeeStatus::Terminated]);

    $event = IntegrationEvent::query()->where('event_type', 'employee.status_changed')->latest('id')->first();
    expect($event->change_kind)->toBe('tombstone')
        ->and($event->payload['status'])->toBe('terminated')
        ->and($event->payload['previous_status'])->toBe('active')
        ->and($event->organization_id)->toBe($this->s->woreda->id);
});

it('orders an assignment lifecycle by aggregate sequence', function (): void {
    $assignment = EmployeeAssignment::query()->findOrFail($this->s->woredaEmployee->current_assignment_id);
    $assignment->update(['effective_from' => now()->subMonths(10)->toDateString()]);
    $assignment->update(['assignment_status' => AssignmentStatus::Closed, 'is_current' => false, 'effective_to' => now()->toDateString()]);

    $events = IntegrationEvent::query()->where('aggregate_uuid', $assignment->id)->orderBy('sequence')->get();
    expect($events->pluck('event_type')->all())->toBe(['assignment.created', 'assignment.updated', 'assignment.closed'])
        ->and($events->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($events->last()->change_kind)->toBe('tombstone');

    IntegrationScenario::publish();
    $positions = IntegrationEvent::query()->where('aggregate_uuid', $assignment->id)->orderBy('sequence')->pluck('feed_position')->all();
    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('emits one revocation event and none for a repeated revoke', function (): void {
    $card = $this->s->woredaCard;
    app(RevokeCardAction::class)->execute($card, IntegrationScenario::admin(), 'lost');

    $revocations = IntegrationEvent::query()->where('event_type', 'id_card.revoked')->where('aggregate_uuid', $card->id)->get();
    expect($revocations)->toHaveCount(1)
        ->and($revocations[0]->payload['card_uuid'])->toBe($card->public_card_uuid)
        ->and($revocations[0]->payload['employee_uuid'])->toBe($this->s->woredaEmployee->id)
        ->and($revocations[0]->change_kind)->toBe('tombstone');

    expect(fn () => app(RevokeCardAction::class)->execute($card->fresh(), IntegrationScenario::admin(), 'again'))->toThrow(DomainException::class);
    $card->fresh()->update(['revoked_at' => $card->fresh()->revoked_at]);

    expect(IntegrationEvent::query()->where('event_type', 'id_card.revoked')->where('aggregate_uuid', $card->id)->count())->toBe(1);
});

it('archives a vacant position as a tombstone', function (): void {
    $position = IntegrationScenario::position($this->s->bureau, 'EVT-VACANT');
    app(ArchivePositionAction::class)->execute($position, IntegrationScenario::admin(), 'restructure');

    $archived = IntegrationEvent::query()->where('event_type', 'position.archived')->where('aggregate_uuid', $position->id)->sole();
    expect($archived->change_kind)->toBe('tombstone')
        ->and($archived->payload)->toBe(['position_uuid' => $position->id, 'organization_uuid' => $this->s->bureau->id, 'state' => 'archived', 'reason_category' => 'archived']);
});

it('records organization changes against the organization itself', function (): void {
    $this->s->bureau->update(['name_en' => 'Renamed Bureau']);

    $event = IntegrationEvent::query()->where('event_type', 'organization.updated')->latest('id')->first();
    expect($event->organization_id)->toBe($this->s->bureau->id)
        ->and($event->payload['changed_fields'])->toBe(['name_en'])
        ->and($event->payload['name_en'])->toBe('Renamed Bureau');
});

it('records nothing while the outbox is switched off', function (): void {
    config(['integration.events.enabled' => false]);
    $before = IntegrationEvent::query()->count();

    $this->s->bureau->update(['name_en' => 'Quiet']);

    expect(IntegrationEvent::query()->count())->toBe($before)->and(IntegrationEventRecorder::enabled())->toBeFalse();
});

it('keeps the domain change when the queue backend is unavailable', function (): void {
    config(['queue.default' => 'redis', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);

    $this->s->bureau->update(['name_en' => 'Queue Down Bureau']);

    expect($this->s->bureau->fresh()->name_en)->toBe('Queue Down Bureau')
        ->and(IntegrationEvent::query()->where('event_type', 'organization.updated')->where('status', 'pending')->exists())->toBeTrue();

    // The publisher needs no queue either.
    expect(IntegrationScenario::publish()['published'])->toBeGreaterThan(0);
});

it('refuses an event that breaks its contract instead of delivering it', function (): void {
    $event = IntegrationEvent::query()->create([
        'event_id' => (string) Str::uuid7(), 'event_type' => 'employee.updated', 'event_version' => 1, 'aggregate_type' => 'employee',
        'aggregate_uuid' => $this->s->woredaEmployee->id, 'sequence' => 999, 'organization_id' => $this->s->woreda->id,
        'occurred_at' => now(), 'payload' => ['employee_uuid' => $this->s->woredaEmployee->id, 'national_id' => '1234'], 'status' => 'pending',
        'available_at' => now(), 'created_at' => now(),
    ]);

    IntegrationScenario::publish();

    expect($event->fresh()->status)->toBe('rejected')
        ->and($event->fresh()->feed_position)->toBeNull()
        ->and($event->fresh()->error)->toContain('unexpected_field:national_id');
});

it('numbers the feed in commit order, gap-free, across repeated and concurrent publisher runs', function (): void {
    $publisher = app(OutboxPublisher::class);
    $publisher->publish();
    $publisher->publish();

    $positions = IntegrationEvent::query()->whereNotNull('feed_position')->orderBy('feed_position')->pluck('feed_position')->all();
    expect($positions)->toBe(range(1, count($positions)))
        ->and((int) DB::table('integration_feed_state')->value('last_position'))->toBe(count($positions));

    // A row whose transaction "commits late" (not yet visible) gets a later
    // position, never one below what a consumer has already read.
    $late = IntegrationEvent::query()->latest('id')->first()->replicate(['feed_position', 'published_at']);
    $late->forceFill(['event_id' => (string) Str::uuid7(), 'sequence' => 5000, 'status' => 'pending', 'available_at' => now()->addMinute()])->save();
    $this->s->bureau->update(['name_en' => 'After Late']);
    $publisher->publish();
    $late->forceFill(['available_at' => now()->subSecond()])->save();
    $publisher->publish();

    expect($late->fresh()->feed_position)->toBe(count($positions) + 2);
});

it('defines every catalog type with a scope and an identity field', function (): void {
    foreach (EventCatalog::types() as $type) {
        expect(EventCatalog::scope($type))->not->toBe('')
            ->and(EventCatalog::dataKeys($type)[0])->toEndWith('_uuid');
    }
});
