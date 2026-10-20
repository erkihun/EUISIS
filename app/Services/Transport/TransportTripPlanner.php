<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Enums\Transport\TransportTripStatus;
use App\Models\TransportSchedule;
use App\Models\TransportTrip;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns schedules into trips for a service date. Idempotent: the unique
 * index on (schedule, date) means a second run, or two servers running at
 * once, cannot plan a trip twice. A cancelled trip is not re-planned.
 */
class TransportTripPlanner
{
    public function __construct(
        private readonly TransportServiceCalendar $calendar,
        private readonly TransportTripService $trips,
    ) {}

    /**
     * @return array{planned: int, skipped: int, existing: int}
     */
    public function planDate(Carbon $date, ?string $providerId = null): array
    {
        $counts = ['planned' => 0, 'skipped' => 0, 'existing' => 0];
        $day = $date->toDateString();

        TransportSchedule::query()
            ->with(['route', 'defaultVehicle'])
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->when($providerId !== null, fn ($q) => $q->where('provider_id', $providerId))
            ->whereHas('route', fn ($q) => $q->where('is_active', true))
            ->orderBy('planned_departure')
            ->chunkById(200, function ($schedules) use ($date, $day, &$counts): void {
                foreach ($schedules as $schedule) {
                    if (! $this->calendar->runsOn($schedule, $date)) {
                        $counts['skipped']++;

                        continue;
                    }

                    $exists = TransportTrip::query()->where('transport_schedule_id', $schedule->id)->whereDate('trip_date', $day)
                        ->where('status', '<>', TransportTripStatus::Cancelled->value)->exists();
                    if ($exists) {
                        $counts['existing']++;

                        continue;
                    }

                    try {
                        DB::transaction(fn () => $this->plan($schedule, $date));
                        $counts['planned']++;
                    } catch (UniqueConstraintViolationException) {
                        $counts['existing']++;
                    }
                }
            });

        return $counts;
    }

    private function plan(TransportSchedule $schedule, Carbon $date): TransportTrip
    {
        $vehicle = $schedule->defaultVehicle;
        $vehicleId = $vehicle !== null && $vehicle->isAvailableOn($date) ? $vehicle->id : null;

        return $this->trips->plan($schedule->provider, [
            'transport_route_id' => $schedule->transport_route_id,
            'transport_schedule_id' => $schedule->id,
            'transport_vehicle_id' => $vehicleId,
            'transport_driver_id' => $vehicleId !== null ? $schedule->default_driver_id : null,
            'direction' => $schedule->direction,
            'trip_date' => $date->toDateString(),
            'departure_time' => $schedule->departureHm(),
            'arrival_time' => $schedule->arrivalHm(),
            'capacity' => null,
        ], null);
    }
}
