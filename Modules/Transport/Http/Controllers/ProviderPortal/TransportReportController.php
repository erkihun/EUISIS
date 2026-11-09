<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Http\Requests\TransportReportRequest;

class TransportReportController extends Controller
{
    public function index(TransportReportRequest $request, ProviderPortalSession $session, TransportReportService $reports): Response
    {
        $providerId = $session->providerId($request);
        abort_if($providerId === null || ! $session->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));
        $filters = $reports->normaliseFilters($request->validated());

        return Inertia::render('ProviderPortal/Transport/Reports/Index', [
            'filters' => $filters,
            'report' => $reports->report($reports->forProvider($providerId, $filters), byProvider: false),
        ]);
    }
}
