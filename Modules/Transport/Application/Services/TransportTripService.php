<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Transport\Domain\Enums\TransportDirection;
use Modules\Transport\Domain\Enums\TransportExceptionType;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportDriver;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Domain\Models\TransportTripVehicleChange;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Infrastructure\Jobs\NotifyTransportTripCancelled;

/**
 * The trip lifecycle (TransportTripStatus) for dispatchers in the portal,
 * staff on the admin pages and devices on the API. Every transition is
 * checked against the state machine, stamped with who did it and audited;
 * a cancellation needs a reason, a replaced vehicle keeps the earlier
 * boardings on the vehicle they were made on.
 */
class TransportTripService
{
    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly TransportExceptionRecorder $exceptions,
    ) {}

    /**
     * Plan a trip. Dispatchers plan on their own provider; staff with the
     * approve-extra permission plan extra trips a provider asked for.
     *
     * @param  array<string, mixed>  $data
     */
    public function plan(Provider $provider, array $data, ProviderUser|User|null $actor, ?Request $request = null): TransportTrip
    {
        $data['trip_number'] = $data['trip_number'] ?? $this->newTripNumber();
        $data['status'] = ($data['transport_vehicle_id'] ?? null) !== null ? TransportTripStatus::Assigned : TransportTripStatus::Planned;
        $date = Carbon::parse($data['trip_date']);
        $data['planned_departure_at'] = isset($data['departure_time']) ? $date->copy()->setTimeFromTimeString($data['departure_time']) : null;
        $data['planned_arrival_at'] = isset($data['arrival_time']) ? $date->copy()->setTimeFromTimeString($data['arrival_time']) : null;

        $trip = TransportTrip::query()->create($data + [
            'provider_id' => $provider->id,
            'created_by' => $actor instanceof User ? $actor->id : null,
        ]);

        $this->audit->execute(AuditEventType::TransportTripCreated, $actor instanceof User ? $actor : null, $trip, null,
            newValues: ['trip_number' => $trip->trip_number, 'provider_id' => $provider->id, 'trip_date' => $trip->trip_date->toDateString(), 'direction' => $trip->direction?->value, 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null, 'is_extra' => (bool) $trip->is_extra],
            request: $request);

        return $trip;
    }

    /** Assign (or re-assign, before departure) the vehicle and driver. */
    public function assign(TransportTrip $trip, ?TransportVehicle $vehicle, ?TransportDriver $driver, ProviderUser|User|null $actor, ?Request $request = null): TransportTrip
    {
        return DB::transaction(function () use ($trip, $vehicle, $driver, $actor, $request): TransportTrip {
            $locked = $this->locked($trip);
            $this->guardOwnership($locked, $vehicle, $driver);

            if (! in_array($locked->status, [TransportTripStatus::Planned, TransportTripStatus::Assigned], true)) {
                throw new DomainException(__('transport.trip_transition_invalid'));
            }
            if ($vehicle !== null && ! $vehicle->isAvailableOn($locked->trip_date)) {
                throw new DomainException(__('transport.vehicle_unavailable'));
            }

            $before = ['vehicle' => $locked->transport_vehicle_id, 'driver' => $locked->transport_driver_id];
            $locked->fill([
                'transport_vehicle_id' => $vehicle?->id,
                'transport_driver_id' => $driver?->id,
                'status' => $vehicle === null ? TransportTripStatus::Planned : TransportTripStatus::Assigned,
            ])->save();

            $this->audit->execute(AuditEventType::TransportTripDispatched, $actor instanceof User ? $actor : null, $locked, null,
                oldValues: $before, newValues: ['vehicle' => $vehicle?->id, 'driver' => $driver?->id, 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null], request: $request);

            return $locked;
        });
    }

    /**
     * Start boarding: the device (or dispatcher) opens the trip context. A
     * vehicle is required, so every boarding is tied to one; the expected
     * rider count is snapshotted from the route's active assignments.
     */
    public function start(TransportTrip $trip, ProviderUser|User|null $actor, ?Request $request = null, ?string $deviceId = null): TransportTrip
    {
        return DB::transaction(function () use ($trip, $actor, $request, $deviceId): TransportTrip {
            $locked = $this->locked($trip);
            $this->transition($locked, TransportTripStatus::Boarding);

            if ($locked->transport_vehicle_id === null) {
                throw new DomainException(__('transport.trip_needs_vehicle'));
            }
            if ($locked->direction === null) {
                throw new DomainException(__('transport.trip_needs_direction'));
            }

            $locked->forceFill([
                'status' => TransportTripStatus::Boarding,
                'actual_started_at' => now(),
                'started_by_provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null,
                'expected_riders' => $locked->expected_riders ?? $this->expectedRiders($locked),
            ])->save();

            if ($locked->planned_departure_at !== null && now()->diffInMinutes($locked->planned_departure_at, true) > 60) {
                $this->exceptions->record($locked->provider_id, TransportExceptionType::TripOutsideSchedule, [
                    'planned_departure_at' => $locked->planned_departure_at->toIso8601String(), 'started_at' => now()->toIso8601String(),
                ], ['trip_id' => $locked->id, 'vehicle_id' => $locked->transport_vehicle_id, 'device_id' => $deviceId], 'info');
            }

            $this->audit->execute(AuditEventType::TransportTripStarted, $actor instanceof User ? $actor : null, $locked, null,
                newValues: ['vehicle' => $locked->transport_vehicle_id, 'driver' => $locked->transport_driver_id, 'device_id' => $deviceId, 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null], request: $request);

            return $locked;
        });
    }

    /** The bus left the first stop; boarding continues at later stops. */
    public function depart(TransportTrip $trip, ProviderUser|User|null $actor, ?Request $request = null): TransportTrip
    {
        return DB::transaction(function () use ($trip): TransportTrip {
            $locked = $this->locked($trip);
            $this->transition($locked, TransportTripStatus::InProgress);
            $locked->forceFill(['status' => TransportTripStatus::InProgress])->save();

            return $locked;
        });
    }

    public function complete(TransportTrip $trip, ProviderUser|User|null $actor, ?Request $request = null, ?string $notes = null, ?string $distanceKm = null): TransportTrip
    {
        return DB::transaction(function () use ($trip, $actor, $request, $notes, $distanceKm): TransportTrip {
            $locked = $this->locked($trip);
            $this->transition($locked, TransportTripStatus::Completed);

            $boardings = TransportTransaction::query()->where('transport_trip_id', $locked->id)->verified()->count();
            $locked->forceFill([
                'status' => TransportTripStatus::Completed,
                'actual_ended_at' => now(),
                'completed_by_provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null,
                'completion_notes' => $notes,
                'distance_km' => $distanceKm ?? $locked->distance_km,
                // The counter is reconciled to the evidence on completion.
                'boarded_count' => $boardings,
            ])->save();

            $this->audit->execute(AuditEventType::TransportTripCompleted, $actor instanceof User ? $actor : null, $locked, null,
                newValues: ['boardings' => $boardings, 'exceptions' => $locked->exceptions()->count(), 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null], request: $request);

            return $locked;
        });
    }

    public function cancel(TransportTrip $trip, string $reason, ProviderUser|User|null $actor, ?Request $request = null, bool $failed = false): TransportTrip
    {
        if (trim($reason) === '') {
            throw new DomainException(__('transport.cancellation_reason_required'));
        }

        return DB::transaction(function () use ($trip, $reason, $actor, $request, $failed): TransportTrip {
            $locked = $this->locked($trip);
            $target = $failed ? TransportTripStatus::Failed : TransportTripStatus::Cancelled;
            $this->transition($locked, $target);

            $locked->forceFill([
                'status' => $target,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by_provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null,
                'cancelled_by_user_id' => $actor instanceof User ? $actor->id : null,
                'actual_ended_at' => $locked->actual_started_at !== null ? now() : null,
            ])->save();

            $this->exceptions->record($locked->provider_id, $failed ? TransportExceptionType::TripFailed : TransportExceptionType::TripCancelled, [
                'reason' => $reason, 'boardings_before' => $locked->boarded_count,
            ], ['trip_id' => $locked->id, 'vehicle_id' => $locked->transport_vehicle_id], $locked->boarded_count > 0 ? 'warning' : 'info');

            $this->audit->execute(AuditEventType::TransportTripCancelled, $actor instanceof User ? $actor : null, $locked, null,
                newValues: ['status' => $target->value, 'boardings' => $locked->boarded_count, 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null], reason: $reason, request: $request);

            if ($locked->trip_date->gte(today())) {
                NotifyTransportTripCancelled::dispatch($locked->id)->afterCommit();
            }

            return $locked;
        });
    }

    /** A replacement bus (and optionally driver) during service; earlier boardings keep their vehicle. */
    public function replaceVehicle(TransportTrip $trip, TransportVehicle $vehicle, ?TransportDriver $driver, string $reason, ProviderUser|User|null $actor, ?Request $request = null): TransportTrip
    {
        if (trim($reason) === '') {
            throw new DomainException(__('transport.substitution_reason_required'));
        }

        return DB::transaction(function () use ($trip, $vehicle, $driver, $reason, $actor, $request): TransportTrip {
            $locked = $this->locked($trip);
            $this->guardOwnership($locked, $vehicle, $driver);
            if ($locked->status->isTerminal()) {
                throw new DomainException(__('transport.trip_transition_invalid'));
            }
            if ($vehicle->id === $locked->transport_vehicle_id) {
                throw new DomainException(__('transport.same_vehicle'));
            }

            $change = TransportTripVehicleChange::query()->create([
                'transport_trip_id' => $locked->id,
                'from_vehicle_id' => $locked->transport_vehicle_id,
                'to_vehicle_id' => $vehicle->id,
                'from_driver_id' => $locked->transport_driver_id,
                'to_driver_id' => $driver?->id ?? $locked->transport_driver_id,
                'reason' => $reason,
                'changed_at' => now(),
                'changed_by_provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null,
                'changed_by_user_id' => $actor instanceof User ? $actor->id : null,
            ]);

            $locked->forceFill([
                'transport_vehicle_id' => $vehicle->id,
                'transport_driver_id' => $driver?->id ?? $locked->transport_driver_id,
                // The trip's own capacity, if any, was set for the first bus.
                'capacity' => null,
            ])->save();

            $this->exceptions->record($locked->provider_id, TransportExceptionType::VehicleSubstituted, [
                'from_vehicle_id' => $change->from_vehicle_id, 'to_vehicle_id' => $vehicle->id, 'reason' => $reason, 'boardings_before' => $locked->boarded_count,
            ], ['trip_id' => $locked->id, 'vehicle_id' => $vehicle->id], 'info');

            $this->audit->execute(AuditEventType::TransportTripVehicleReplaced, $actor instanceof User ? $actor : null, $locked, null,
                oldValues: ['vehicle' => $change->from_vehicle_id], newValues: ['vehicle' => $vehicle->id, 'provider_user_id' => $actor instanceof ProviderUser ? $actor->id : null], reason: $reason, request: $request);

            return $locked;
        });
    }

    /** Employees assigned to the route (valid today) — the denominator for no-show. */
    public function expectedRiders(TransportTrip $trip): int
    {
        return TransportPass::query()
            ->where('transport_route_id', $trip->transport_route_id)
            ->validOn($trip->trip_date->toDateString())
            ->distinct()
            ->count('employee_id');
    }

    /**
     * TRP-YYYYMMDD-XXXXXX with a random suffix: unique without counting (a
     * count collides under concurrency and after any deletion).
     */
    public function newTripNumber(): string
    {
        do {
            $number = 'TRP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (TransportTrip::query()->where('trip_number', $number)->exists());

        return $number;
    }

    public static function directionFor(string $value): TransportDirection
    {
        return TransportDirection::from($value);
    }

    private function locked(TransportTrip $trip): TransportTrip
    {
        return TransportTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
    }

    private function transition(TransportTrip $trip, TransportTripStatus $to): void
    {
        if (! $trip->status->canTransitionTo($to)) {
            throw new DomainException(__('transport.trip_transition_invalid', ['from' => $trip->status->value, 'to' => $to->value]));
        }
    }

    private function guardOwnership(TransportTrip $trip, ?TransportVehicle $vehicle, ?TransportDriver $driver): void
    {
        if (($vehicle !== null && $vehicle->provider_id !== $trip->provider_id) || ($driver !== null && $driver->provider_id !== $trip->provider_id)) {
            throw new DomainException(__('transport.own_data_only'));
        }
    }
}
