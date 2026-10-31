<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\TransportDashboardService;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Resources\TransportTransactionResource;
use Modules\Transport\Http\Resources\TransportTripResource;

class TransportDashboardController extends Controller
{
    public function __invoke(Request $request, ProviderPortalSession $session, TransportDashboardService $dashboard): Response
    {
        $providerId = $session->providerId($request);
        abort_if($providerId === null || ! $session->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        $summary = $dashboard->summary($providerId);

        return Inertia::render('ProviderPortal/Transport/Dashboard', [
            'stats' => collect($summary)->except('recent_scans')->all(),
            'recentTransactions' => TransportTransactionResource::collection($summary['recent_scans'])->resolve(),
            'todayTrips' => TransportTripResource::collection(TransportTrip::query()->with(['route', 'vehicle', 'driver'])->where('provider_id', $providerId)->whereDate('trip_date', today())->orderBy('departure_time')->limit(50)->get())->resolve(),
            'openExceptions' => TransportException::query()->where('provider_id', $providerId)->where('status', 'open')->count(),
        ]);
    }
}
