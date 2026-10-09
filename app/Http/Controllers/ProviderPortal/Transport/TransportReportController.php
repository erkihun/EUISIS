<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportReportRequest;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportReportService;
use Inertia\Inertia;
use Inertia\Response;

class TransportReportController extends Controller
{
    public function index(TransportReportRequest $request, ProviderPortalContext $context, TransportReportService $reports): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));
        $validated = $request->validated();

        return Inertia::render('ProviderPortal/Transport/Reports/Index', [
            'filters' => $validated,
            'summary' => $reports->transactionSummary($provider, $validated['date_from'] ?? null, $validated['date_to'] ?? null),
        ]);
    }
}
