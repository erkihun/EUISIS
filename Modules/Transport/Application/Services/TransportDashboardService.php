<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use Illuminate\Support\Carbon;
use Modules\Transport\Domain\Models\TransportDriver;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Domain\Models\TransportVehicle;

class TransportDashboardService
{
    /** @return array<string, mixed> */
    public function summary(string $providerId): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'today_scans' => TransportTransaction::query()->where('provider_id', $providerId)->whereDate('transaction_date', $today)->count(),
            'accepted_scans' => TransportTransaction::query()->where('provider_id', $providerId)->whereDate('transaction_date', $today)->where('status', 'accepted')->count(),
            'rejected_scans' => TransportTransaction::query()->where('provider_id', $providerId)->whereDate('transaction_date', $today)->where('status', 'rejected')->count(),
            'active_routes' => TransportRoute::query()->where('provider_id', $providerId)->where('is_active', true)->count(),
            'active_vehicles' => TransportVehicle::query()->where('provider_id', $providerId)->where('status', 'active')->count(),
            'active_drivers' => TransportDriver::query()->where('provider_id', $providerId)->where('status', 'active')->count(),
            'scheduled_trips' => TransportTrip::query()->where('provider_id', $providerId)->whereDate('trip_date', $today)->where('status', 'scheduled')->count(),
            'completed_trips' => TransportTrip::query()->where('provider_id', $providerId)->whereDate('trip_date', $today)->where('status', 'completed')->count(),
            'active_passes' => TransportPass::query()->where('provider_id', $providerId)->where('status', 'active')->count(),
            'recent_scans' => TransportTransaction::query()
                ->with(['employee.currentAssignment.organization', 'route', 'trip'])
                ->where('provider_id', $providerId)
                ->latest('scanned_at')
                ->limit(8)
                ->get(),
        ];
    }
}
