<?php

declare(strict_types=1);

namespace Modules\Transport\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Transport\Application\Services\TransportEmployeeNotifier;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportTrip;

/**
 * Tells the employees assigned to the route that a trip was cancelled. A
 * service notice only: it names the route, date and departure, no one else.
 */
class NotifyTransportTripCancelled implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tripId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TransportEmployeeNotifier $notifier): void
    {
        $trip = TransportTrip::query()->with('route')->find($this->tripId);
        if ($trip === null || $trip->route === null) {
            return;
        }

        $context = [
            'date' => $trip->trip_date->toDateString(),
            'time' => $trip->departure_time !== null ? substr((string) $trip->departure_time, 0, 5) : '',
            'direction' => $trip->direction?->value,
            'note' => $trip->cancellation_reason,
        ];

        TransportPass::query()->with('employee')
            ->where('transport_route_id', $trip->transport_route_id)
            ->validOn($trip->trip_date->toDateString())
            ->chunkById(200, function ($passes) use ($notifier, $trip, $context): void {
                foreach ($passes as $pass) {
                    if ($pass->employee !== null) {
                        $notifier->tripCancelled($pass->employee, $trip->route, $context);
                    }
                }
            });
    }
}
