<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportReportRequest;
use App\Models\Provider;
use App\Services\Transport\TransportReportService;
use Inertia\Inertia;
use Inertia\Response;

/** Boardings across providers, limited to employees in the viewer's organization scope. */
class TransportReportController extends Controller
{
    public function index(TransportReportRequest $request, TransportReportService $reports): Response
    {
        $filters = $reports->normaliseFilters($request->validated());

        return Inertia::render('Transport/Reports/Index', [
            'filters' => $filters,
            'report' => $reports->report($reports->forAdmin($request->user(), $filters), byProvider: true),
            'providers' => self::providerOptions(),
            'can' => [
                'viewTransactions' => $request->user()->can('transport-transactions.viewAny'),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function providerOptions(): array
    {
        return Provider::query()
            ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
            ->orderBy('name_en')
            ->get(['id', 'provider_code', 'name_en', 'name_am'])
            ->toArray();
    }
}
