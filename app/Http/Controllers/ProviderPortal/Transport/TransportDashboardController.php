<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransportTransactionResource;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportDashboardController extends Controller
{
    public function __invoke(Request $request, ProviderPortalContext $context, TransportDashboardService $dashboard): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        $summary = $dashboard->summary($provider);

        return Inertia::render('ProviderPortal/Transport/Dashboard', [
            'stats' => collect($summary)->except('recent_scans')->all(),
            'recentTransactions' => TransportTransactionResource::collection($summary['recent_scans'])->resolve(),
        ]);
    }
}
