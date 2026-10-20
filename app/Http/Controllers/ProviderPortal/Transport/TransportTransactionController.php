<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransportTransactionResource;
use App\Models\TransportTransaction;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportTransactionController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        $filters = $this->filters($request);
        $transactions = $this->filtered($provider->id, $filters)
            ->with(['employee.currentAssignment.organization', 'route', 'trip'])
            ->latest('scanned_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Transactions/Index', [
            'transactions' => TransportTransactionResource::collection($transactions)->response()->getData(true),
            'filters' => $filters,
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

        // lazyById walks the primary key, so no other ordering (it would break the keyset).
        $query = $this->filtered($provider->id, $this->filters($request))->with(['employee', 'route']);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, csv_safe_row(['date', 'status', 'employee_number', 'employee_name', 'route', 'result_code']));
            // Streamed in chunks: an export never holds a whole year in memory.
            foreach ($query->lazyById(500, 'id') as $row) {
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

    /** @return array{date_from: string|null, date_to: string|null, status: string|null} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'in:accepted,rejected'],
        ]);

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'status' => $validated['status'] ?? null,
        ];
    }

    /**
     * @param  array{date_from: string|null, date_to: string|null, status: string|null}  $filters
     * @return Builder<TransportTransaction>
     */
    private function filtered(string $providerId, array $filters): Builder
    {
        return TransportTransaction::query()
            ->where('provider_id', $providerId)
            ->when($filters['date_from'], fn (Builder $q, string $from) => $q->whereDate('transaction_date', '>=', $from))
            ->when($filters['date_to'], fn (Builder $q, string $to) => $q->whereDate('transaction_date', '<=', $to))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status));
    }
}
