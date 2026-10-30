<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Application\Services\TransportTripPlanner;
use Modules\Transport\Application\Services\TransportTripService;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportServiceCalendarDay;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Tests\Support\TransportScenario;

/*
 * Route != trip. Schedules plan trips per service day (weekdays, public
 * holidays, operating/suspended overrides) and the lifecycle moves a trip
 * from planned to completed with every step stamped, reasoned and audited.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 05:00')); // a Wednesday
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->route = $this->s->route($this->provider);
    $this->vehicle = $this->s->vehicle($this->provider);
    $this->manager = $this->s->portalUser($this->provider, 'manager');
    $this->planner = app(TransportTripPlanner::class);
    $this->trips = app(TransportTripService::class);
});

it('plans one trip per schedule per service day, and never twice', function (): void {
    $morning = $this->s->schedule($this->route, ['default_vehicle_id' => $this->vehicle->id]);
    $evening = $this->s->schedule($this->route, ['direction' => 'outbound', 'planned_departure' => '17:30', 'planned_arrival' => null]);
    $this->s->schedule($this->route, ['service_days' => [6, 7], 'planned_departure' => '09:00']); // weekends only

    $counts = $this->planner->planDate(Carbon::parse('2026-10-14'));
    expect($counts)->toBe(['planned' => 2, 'skipped' => 1, 'existing' => 0]);

    $planned = TransportTrip::query()->orderBy('departure_time')->get();
    expect($planned)->toHaveCount(2)
        ->and($planned[0]->direction->value)->toBe('inbound')
        ->and($planned[0]->status)->toBe(TransportTripStatus::Assigned) // the default vehicle was free
        ->and($planned[0]->transport_schedule_id)->toBe($morning->id)
        ->and($planned[0]->planned_departure_at->format('H:i'))->toBe('06:30')
        ->and($planned[1]->status)->toBe(TransportTripStatus::Planned)
        ->and($planned[1]->transport_schedule_id)->toBe($evening->id);

    // Same day again: nothing new. Next day too: the weekday schedules run again.
    expect($this->planner->planDate(Carbon::parse('2026-10-14'))['existing'])->toBe(2)
        ->and($this->planner->planDate(Carbon::parse('2026-10-15'))['planned'])->toBe(2)
        ->and(TransportTrip::query()->count())->toBe(4);

    // A cancelled trip is re-planned; nothing else is.
    $this->trips->cancel($planned[1], 'bus broke down', $this->manager);
    expect($this->planner->planDate(Carbon::parse('2026-10-14'))['planned'])->toBe(1);

    $this->artisan('transport:plan-trips', ['--date' => '2026-10-16', '--days' => 1])->assertSuccessful();
    expect(TransportTrip::query()->whereDate('trip_date', '2026-10-16')->count())->toBe(2);
});

it('follows the public holiday calendar and the transport operating or suspended days', function (): void {
    $this->s->schedule($this->route, ['skips_public_holidays' => true]);
    $other = $this->s->schedule($this->s->route($this->provider), ['skips_public_holidays' => false]);
    PublicHoliday::query()->create(['name_en' => 'Holiday', 'name_am' => 'በዓል', 'holiday_date' => '2026-10-15', 'is_recurring' => false, 'is_active' => true]);

    // Holiday: only the schedule that runs on holidays.
    expect($this->planner->planDate(Carbon::parse('2026-10-15'))['planned'])->toBe(1)
        ->and(TransportTrip::query()->sole()->transport_route_id)->toBe($other->transport_route_id);

    // A citywide suspended day stops everything; a route-level operating day on a Sunday runs that route only.
    TransportServiceCalendarDay::query()->create(['calendar_date' => '2026-10-16', 'kind' => 'suspended', 'name_en' => 'Suspended']);
    expect($this->planner->planDate(Carbon::parse('2026-10-16'))['skipped'])->toBe(2);
    TransportServiceCalendarDay::query()->create(['calendar_date' => '2026-10-18', 'kind' => 'operating', 'transport_route_id' => $this->route->id]);
    expect($this->planner->planDate(Carbon::parse('2026-10-18')))->toBe(['planned' => 1, 'skipped' => 1, 'existing' => 0]);
});

it('runs the lifecycle: a vehicle to start, boarding at later stops, a reconciled count on completion', function (): void {
    $trip = $this->s->trip($this->route, ['status' => 'planned', 'transport_vehicle_id' => null, 'actual_started_at' => null]);

    expect(fn () => $this->trips->start($trip, $this->manager))->toThrow(DomainException::class);

    $this->trips->assign($trip, $this->vehicle, null, $this->manager);
    expect($trip->fresh()->status)->toBe(TransportTripStatus::Assigned);

    $started = $this->trips->start($trip->fresh(), $this->manager);
    expect($started->status)->toBe(TransportTripStatus::Boarding)
        ->and($started->actual_started_at)->not->toBeNull()
        ->and($started->started_by_provider_user_id)->toBe($this->manager->id);

    // Once underway, boarding still happens at intermediate stops.
    $this->trips->depart($started, $this->manager);
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->route);
    $decision = app(TransportBoardingService::class)->board(new BoardingRequest(
        provider: $this->provider, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $this->manager,
    ));
    expect($decision->accepted)->toBeTrue();

    // The counter is corrected to the evidence on completion (a void after boarding counts down).
    TransportTrip::query()->whereKey($trip->id)->update(['boarded_count' => 7]);
    $completed = $this->trips->complete($trip->fresh(), $this->manager, null, 'all well', '18.40');
    expect($completed->status)->toBe(TransportTripStatus::Completed)
        ->and($completed->boarded_count)->toBe(1)
        ->and((string) $completed->distance_km)->toBe('18.40')
        ->and($completed->completed_by_provider_user_id)->toBe($this->manager->id);

    // Terminal: nothing moves it again, and a completed trip takes no boarding.
    expect(fn () => $this->trips->cancel($completed, 'late', $this->manager))->toThrow(DomainException::class);
    expect(AuditLog::query()->whereIn('event_type', ['transport_trip.dispatched', 'transport_trip.started', 'transport_trip.completed'])->count())->toBe(3);
});

it('needs a reason to cancel, records the exception and keeps any boarding evidence', function (): void {
    $trip = $this->s->trip($this->route);
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->route);
    app(TransportBoardingService::class)->board(new BoardingRequest(
        provider: $this->provider, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $this->manager,
    ));

    expect(fn () => $this->trips->cancel($trip, '  ', $this->manager))->toThrow(DomainException::class);

    $cancelled = $this->trips->cancel($trip, 'Road closed at Megenagna', $this->manager);
    expect($cancelled->status)->toBe(TransportTripStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Road closed at Megenagna')
        ->and($cancelled->cancelled_by_provider_user_id)->toBe($this->manager->id)
        ->and(TransportTransaction::query()->where('status', 'accepted')->count())->toBe(1)
        ->and(TransportException::query()->where('type', 'trip_cancelled')->sole()->severity)->toBe('warning');

    // A failed trip is the same movement with its own label.
    $other = $this->s->trip($this->route);
    expect($this->trips->cancel($other, 'Breakdown', $this->manager, failed: true)->status)->toBe(TransportTripStatus::Failed);
});

it('replaces the vehicle mid-trip: earlier boardings stay on the first bus, later ones go to the second', function (): void {
    $trip = $this->s->trip($this->route, ['transport_vehicle_id' => $this->vehicle->id, 'capacity' => 10]);
    $replacement = $this->s->vehicle($this->provider, ['capacity' => 30]);
    $engine = app(TransportBoardingService::class);
    $board = function () use ($engine, $trip) {
        [$employee, $card] = $this->s->employee();
        $this->s->pass($employee, $this->provider, $this->route);

        return $engine->board(new BoardingRequest(
            provider: $this->provider, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
            credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $this->manager,
        ));
    };

    expect($board()->accepted)->toBeTrue();
    expect(fn () => $this->trips->replaceVehicle($trip, $this->vehicle, null, 'same bus', $this->manager))->toThrow(DomainException::class);
    expect(fn () => $this->trips->replaceVehicle($trip, $this->s->vehicle($this->s->provider('Rival')), null, 'not ours', $this->manager))->toThrow(DomainException::class);

    $this->trips->replaceVehicle($trip, $replacement, null, 'Engine failure at stop 3', $this->manager);
    expect($board()->accepted)->toBeTrue();

    $vehicles = TransportTransaction::query()->where('status', 'accepted')->orderBy('created_at')->pluck('transport_vehicle_id')->all();
    expect($vehicles)->toBe([$this->vehicle->id, $replacement->id])
        ->and($trip->fresh()->transport_vehicle_id)->toBe($replacement->id)
        ->and($trip->fresh()->capacity)->toBeNull()
        ->and($trip->fresh()->effectiveCapacity())->toBe(30)
        ->and($trip->fresh()->vehicleChanges()->sole()->reason)->toBe('Engine failure at stop 3')
        ->and(TransportException::query()->where('type', 'vehicle_substituted')->count())->toBe(1);
});
