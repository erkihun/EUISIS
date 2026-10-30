<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Queries;

use Carbon\CarbonInterface;
use Modules\Transport\Contracts\Data\EmployeeTransportSummary;
use Modules\Transport\Contracts\EmployeeTransportActivity;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;

/**
 * The employee's own transport: the route and stops assigned, today's trips
 * on that route, and the boardings recorded against the card. Nothing
 * financial: a boarding is evidence of service, not a charge.
 */
final class EmployeeTransportActivityQuery implements EmployeeTransportActivity
{
    public function forEmployee(string $employeeId, CarbonInterface $today): EmployeeTransportSummary
    {
        $passes = TransportPass::query()
            ->where('employee_id', $employeeId)
            ->with(['route:id,route_code,name_en,name_am,origin_en,origin_am,destination_en,destination_am', 'provider:id,name_en,name_am', 'boardingStop:id,name_en,name_am', 'eveningStop:id,name_en,name_am'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('valid_until')
            ->limit(5)
            ->get();
        $current = $passes->first(fn (TransportPass $pass): bool => $pass->status === 'active' && $pass->transport_route_id !== null);

        $todayTrips = $current === null ? collect() : TransportTrip::query()
            ->where('transport_route_id', $current->transport_route_id)
            ->whereDate('trip_date', $today->toDateString())
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->orderBy('departure_time')
            ->get(['id', 'trip_number', 'direction', 'departure_time', 'status', 'boarded_count', 'capacity']);

        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $verifiedThisMonth = TransportTransaction::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'accepted')
            ->whereNull('voided_at')
            ->where('transaction_date', '>=', $monthStart);

        return new EmployeeTransportSummary(
            passes: $passes->map(fn (TransportPass $pass): array => [
                'id' => $pass->id,
                'status' => (string) $pass->status,
                'route' => $pass->route?->name_en,
                'route_am' => $pass->route?->name_am,
                'route_code' => $pass->route?->route_code,
                'origin' => $pass->route?->origin_en,
                'origin_am' => $pass->route?->origin_am,
                'destination' => $pass->route?->destination_en,
                'destination_am' => $pass->route?->destination_am,
                'provider' => $pass->provider?->name_en,
                'provider_am' => $pass->provider?->name_am,
                'boarding_stop' => $pass->boardingStop?->name_en,
                'boarding_stop_am' => $pass->boardingStop?->name_am,
                'evening_stop' => $pass->eveningStop?->name_en,
                'evening_stop_am' => $pass->eveningStop?->name_am,
                'valid_from' => $pass->valid_from?->toDateString(),
                'valid_until' => $pass->valid_until?->toDateString(),
            ])->values()->all(),
            todayTrips: $todayTrips->map(fn (TransportTrip $trip): array => [
                'id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'direction' => $trip->direction?->value,
                'departure_time' => $trip->departure_time !== null ? substr((string) $trip->departure_time, 0, 5) : null,
                'status' => $trip->status?->value,
                'boarded_count' => $trip->boarded_count,
                'capacity' => $trip->effectiveCapacity(),
            ])->values()->all(),
            ridesThisMonth: (clone $verifiedThisMonth)->count(),
            morningRidesThisMonth: (clone $verifiedThisMonth)->where('direction', 'inbound')->count(),
            eveningRidesThisMonth: (clone $verifiedThisMonth)->where('direction', 'outbound')->count(),
            recentBoardings: TransportTransaction::query()
                ->where('employee_id', $employeeId)
                ->with(['route:id,route_code,name_en,name_am', 'provider:id,name_en,name_am', 'stop:id,name_en,name_am'])
                ->orderByDesc('scanned_at')
                ->limit(15)
                ->get()
                ->map(fn (TransportTransaction $tx): array => [
                    'date' => $tx->transaction_date?->toDateString() ?? $tx->scanned_at?->toDateString(),
                    'time' => $tx->scanned_at?->format('H:i'),
                    'route' => $tx->route?->name_en,
                    'route_am' => $tx->route?->name_am,
                    'provider' => $tx->provider?->name_en,
                    'provider_am' => $tx->provider?->name_am,
                    'direction' => $tx->direction?->value,
                    'stop' => $tx->stop?->name_en,
                    'stop_am' => $tx->stop?->name_am,
                    'status' => $tx->voided_at !== null ? 'voided' : (string) $tx->status,
                    'rejection_reason' => $tx->status === 'accepted' ? null : $tx->rejection_reason,
                ])
                ->values()
                ->all(),
        );
    }
}
