<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Application\Services\ProviderPortalContext;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Http\Requests\TransportReportRequest;

class TransportReportController extends Controller
{
    public function index(TransportReportRequest $request, ProviderPortalContext $context, TransportReportService $reports): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));
        $filters = $reports->normaliseFilters($request->validated());

        return Inertia::render('ProviderPortal/Transport/Reports/Index', [
            'filters' => $filters,
            'report' => $reports->report($reports->forProvider($provider, $filters), byProvider: false),
        ]);
    }
}
