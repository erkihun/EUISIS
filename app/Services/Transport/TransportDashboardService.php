<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Provider;
use App\Models\TransportDriver;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use App\Models\TransportTransaction;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use Illuminate\Support\Carbon;

class TransportDashboardService
{
    /** @return array<string, mixed> */
    public function summary(Provider $provider): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'today_scans' => TransportTransaction::query()->where('provider_id', $provider->id)->whereDate('transaction_date', $today)->count(),
            'accepted_scans' => TransportTransaction::query()->where('provider_id', $provider->id)->whereDate('transaction_date', $today)->where('status', 'accepted')->count(),
            'rejected_scans' => TransportTransaction::query()->where('provider_id', $provider->id)->whereDate('transaction_date', $today)->where('status', 'rejected')->count(),
            'active_routes' => TransportRoute::query()->where('provider_id', $provider->id)->where('is_active', true)->count(),
            'active_vehicles' => TransportVehicle::query()->where('provider_id', $provider->id)->where('status', 'active')->count(),
            'active_drivers' => TransportDriver::query()->where('provider_id', $provider->id)->where('status', 'active')->count(),
            'scheduled_trips' => TransportTrip::query()->where('provider_id', $provider->id)->whereDate('trip_date', $today)->where('status', 'scheduled')->count(),
            'completed_trips' => TransportTrip::query()->where('provider_id', $provider->id)->whereDate('trip_date', $today)->where('status', 'completed')->count(),
            'active_passes' => TransportPass::query()->where('provider_id', $provider->id)->where('status', 'active')->count(),
            'recent_scans' => TransportTransaction::query()
                ->with(['employee.currentAssignment.organization', 'route', 'trip'])
                ->where('provider_id', $provider->id)
                ->latest('scanned_at')
                ->limit(8)
                ->get(),
        ];
    }
}
