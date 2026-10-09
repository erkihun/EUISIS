<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Http\Resources\CafeteriaTransactionResource;
use App\Models\CafeteriaTransaction;
use App\Services\Cafeteria\ProviderTransactionExportService;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProviderTransactionController extends Controller
{
    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        $filters = $this->filters($request);

        $transactions = CafeteriaTransaction::query()
            ->with(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'provider', 'consumedDays'])
            ->where('cafeteria_provider_id', $provider->id)
            ->whereBetween('transaction_date', [$filters['start_date'], $filters['end_date']])
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('scanned_at')
            ->paginate(30)
            ->withQueryString();
        $canExport = Gate::forUser($request->user())->allows('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return Inertia::render('Cafeteria/Portal/Transactions/Index', [
            ...$this->portalPayload($request, $context, $provider),
            'transactions' => CafeteriaTransactionResource::collection($transactions)->resolve(),
            'paymentSummary' => $exportService->summaryPayload($provider, $filters),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'filters' => $filters,
            'can' => [
                'exportTransactions' => $canExport,
                'exportXlsx' => $canExport,
                'exportPdf' => $canExport,
            ],
        ]);
    }

    public function show(Request $request, CafeteriaTransaction $transaction, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $transaction->cafeteria_provider_id !== $provider->id, 404);

        $transaction->load(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'provider', 'idCard', 'consumedDays']);

        return Inertia::render('Cafeteria/Portal/Transactions/Show', [
            ...$this->portalPayload($request, $context, $provider),
            'transaction' => (new CafeteriaTransactionResource($transaction))->resolve(),
        ]);
    }

    public function export(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        return $this->exportCsv($request, $context, $exportService);
    }

    public function exportCsv(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->csv($provider, $this->filters($request), $request->user(), $request);
    }

    public function exportPaymentClaim(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->csv($provider, $this->filters($request), $request->user(), $request, true);
    }

    public function exportXlsx(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->xlsx($provider, $this->filters($request), $request->user(), $request);
    }

    public function exportPdf(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->pdf($provider, $this->filters($request), $request->user(), $request);
    }

    public function exportPaymentClaimXlsx(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->xlsx($provider, $this->filters($request), $request->user(), $request, true);
    }

    public function exportPaymentClaimPdf(Request $request, ProviderPortalContext $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->pdf($provider, $this->filters($request), $request->user(), $request, true);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $startDate = $request->string('start_date')->toString()
            ?: $request->string('date')->toString()
            ?: now()->startOfMonth()->toDateString();
        $endDate = $request->string('end_date')->toString()
            ?: $request->string('date')->toString()
            ?: now()->toDateString();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $request->string('status')->toString(),
            'employee_search' => $request->string('employee_search')->toString(),
            'usage_mode' => $request->string('usage_mode')->toString(),
            'subsidy_only' => $request->boolean('subsidy_only'),
            'employee_payable' => $request->boolean('employee_payable'),
            'transaction_type' => $request->string('transaction_type')->toString(),
            'order_id' => $request->string('order_id')->toString(),
            'menu_id' => $request->string('menu_id')->toString(),
        ];
    }
}
