<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportReportRequest;
use App\Http\Resources\TransportTransactionResource;
use App\Services\Transport\TransportReportService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Every boarding scan, for staff: filtered, paginated and limited to their organization scope. */
class TransportTransactionController extends Controller
{
    public function index(TransportReportRequest $request, TransportReportService $reports): Response
    {
        abort_unless($request->user()->can('transport-transactions.viewAny'), 403);
        $filters = $reports->normaliseFilters($request->validated());

        $transactions = $reports->forAdmin($request->user(), $filters)
            ->with(['employee.currentAssignment.organization', 'provider:id,provider_code,name_en,name_am', 'route', 'trip'])
            ->latest('scanned_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Transport/Transactions/Index', [
            'transactions' => TransportTransactionResource::collection($transactions)->response()->getData(true),
            'filters' => $filters,
            'providers' => TransportReportController::providerOptions(),
            'can' => ['export' => $request->user()->can('transport-transactions.export')],
        ]);
    }

    public function export(TransportReportRequest $request, TransportReportService $reports): StreamedResponse
    {
        abort_unless($request->user()->can('transport-transactions.export'), 403);
        $filters = $reports->normaliseFilters($request->validated());
        // lazyById walks the primary key, so no other ordering (it would break the keyset).
        $query = $reports->forAdmin($request->user(), $filters)->with(['employee', 'provider:id,provider_code,name_en', 'route']);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, csv_safe_row(['scanned_at', 'date', 'status', 'result_code', 'provider_code', 'provider', 'route_code', 'route', 'employee_number', 'employee_name']));
            foreach ($query->lazyById(500, 'id') as $row) {
                fputcsv($out, csv_safe_row([
                    $row->scanned_at?->toDateTimeString(),
                    $row->transaction_date?->toDateString(),
                    $row->status,
                    $row->result_code,
                    $row->provider?->provider_code,
                    $row->provider?->name_en,
                    $row->route?->route_code,
                    $row->route?->name_en,
                    $row->employee?->employee_number,
                    $row->employee?->full_name,
                ]));
            }
            fclose($out);
        }, 'transport-transactions-'.$filters['date_from'].'-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
