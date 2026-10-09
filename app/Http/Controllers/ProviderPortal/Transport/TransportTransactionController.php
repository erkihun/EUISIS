<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransportTransactionResource;
use App\Models\TransportTransaction;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportTransactionController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        $transactions = TransportTransaction::query()
            ->with(['employee.currentAssignment.organization', 'route', 'trip'])
            ->where('provider_id', $provider->id)
            ->latest('scanned_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Transactions/Index', [
            'transactions' => TransportTransactionResource::collection($transactions)->response()->getData(true),
        ]);
    }

    public function show(Request $request, ProviderPortalContext $context, TransportTransaction $transaction): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || $transaction->provider_id !== $provider->id, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Transactions/Show', [
            'transaction' => (new TransportTransactionResource($transaction->load(['employee.currentAssignment.organization', 'route', 'trip', 'pass'])))->resolve(),
        ]);
    }

    public function exportCsv(Request $request, ProviderPortalContext $context)
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        $rows = TransportTransaction::query()->with(['employee', 'route'])->where('provider_id', $provider->id)->latest('scanned_at')->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, csv_safe_row(['date', 'status', 'employee_number', 'employee_name', 'route', 'result_code']));
            foreach ($rows as $row) {
                fputcsv($out, csv_safe_row([
                    $row->transaction_date?->toDateString(),
                    $row->status,
                    $row->employee?->employee_number,
                    $row->employee?->full_name,
                    $row->route?->name_en,
                    $row->result_code,
                ]));
            }
        }, 'transport-transactions.csv');
    }
}
