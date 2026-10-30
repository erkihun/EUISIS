<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Cafeteria\Application\Services\Portal\CafeteriaPortalSelector;
use Modules\Cafeteria\Application\Services\ProviderTransactionExportService;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;
use Modules\Cafeteria\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use Modules\Cafeteria\Http\Resources\CafeteriaTransactionResource;
use Modules\Providers\Contracts\Data\PortalActor;
use Modules\Providers\Contracts\ProviderPortalSession;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProviderTransactionController extends Controller
{
    /** Longest period one list, summary or export may cover. */
    private const MAX_PERIOD_DAYS = 366;

    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): Response
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
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

    public function show(Request $request, CafeteriaTransaction $transaction, ProviderPortalSession $context): Response
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null || $transaction->cafeteria_provider_id !== $provider->id, 404);

        $transaction->load(['employee.currentAssignment.organization', 'employee.currentAssignment.position', 'provider', 'idCard', 'consumedDays']);

        return Inertia::render('Cafeteria/Portal/Transactions/Show', [
            ...$this->portalPayload($request, $context, $provider),
            'transaction' => (new CafeteriaTransactionResource($transaction))->resolve(),
        ]);
    }

    public function export(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        return $this->exportCsv($request, $context, $exportService);
    }

    public function exportCsv(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->csv($provider, $this->filters($request), $this->exporter($request), $request);
    }

    public function exportPaymentClaim(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->csv($provider, $this->filters($request), $this->exporter($request), $request, true);
    }

    public function exportXlsx(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->xlsx($provider, $this->filters($request), $this->exporter($request), $request);
    }

    public function exportPdf(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->pdf($provider, $this->filters($request), $this->exporter($request), $request);
    }

    public function exportPaymentClaimXlsx(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->xlsx($provider, $this->filters($request), $this->exporter($request), $request, true);
    }

    public function exportPaymentClaimPdf(Request $request, ProviderPortalSession $context, ProviderTransactionExportService $exportService): SymfonyResponse
    {
        $provider = app(CafeteriaPortalSelector::class)->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));
        Gate::forUser($request->user())->authorize('exportProviderTransactions', [CafeteriaTransaction::class, $provider]);

        return $exportService->pdf($provider, $this->filters($request), $this->exporter($request), $request, true);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        // A bounded, well-formed period: lists, totals and exports never span
        // an unlimited transaction history (risk register SCL-5).
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $startDate = $request->string('start_date')->toString()
            ?: $request->string('date')->toString()
            ?: now()->startOfMonth()->toDateString();
        $endDate = $request->string('end_date')->toString()
            ?: $request->string('date')->toString()
            ?: now()->toDateString();

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => __('provider-portal.period_too_long', ['days' => self::MAX_PERIOD_DAYS]),
            ]);
        }

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

    /** The portal user generating an export, named on it and in the audit trail. */
    private function exporter(Request $request): PortalActor
    {
        $actor = app(ProviderPortalSession::class)->actor($request);
        abort_if($actor === null, 403);

        return $actor;
    }
}
