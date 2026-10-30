<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Application\Services\Settlement\TransportSettlementService;
use Modules\Transport\Application\Services\TransportExportService;
use Modules\Transport\Domain\Enums\TransportAdjustmentOrigin;
use Modules\Transport\Domain\Enums\TransportSettlementStatus;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Domain\Models\TransportInvoice;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportSettlement;
use Modules\Transport\Domain\Models\TransportSettlementAdjustment;
use Modules\Transport\Http\Requests\TransportAdjustmentRequest;
use Modules\Transport\Http\Requests\TransportSettlementComputeRequest;
use Modules\Transport\Http\Resources\TransportContractResource;
use Modules\Transport\Http\Resources\TransportInvoiceResource;
use Modules\Transport\Http\Resources\TransportSettlementResource;

/**
 * Statements and reconciliation. Verified figures come from evidence, the
 * claim from the invoice; staff adjust through recorded adjustments and
 * approve under maker-checker. The provider never writes anything here.
 */
class TransportSettlementController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-settlements.viewAny'), 403);
        $organizations = $scope->isUnrestricted($user) ? null : array_map('strval', $scope->allowedOrganizationIds($user));

        $settlements = TransportSettlement::query()->with(['provider', 'invoice'])->whereNotNull('settlement_number')
            ->when($organizations !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $organizations)))
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('period_start')->latest('created_at')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Settlements/Index', [
            'settlements' => TransportSettlementResource::collection($settlements)->response()->getData(true),
            'filters' => $request->only('provider_id', 'status'),
            'providers' => $this->providers(),
            'statuses' => TransportSettlementStatus::values(),
            'can' => ['manage' => $user->can('transport-settlements.manage')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-settlements.manage'), 403);
        $invoice = $request->invoice_id ? TransportInvoice::query()->with('provider')->find($request->invoice_id) : null;

        return Inertia::render('Transport/Settlements/Create', [
            'providers' => $this->providers(),
            'contracts' => TransportContractResource::collection(TransportContract::query()->with('provider')->whereIn('status', ['active', 'superseded', 'ended'])->orderBy('contract_reference')->orderByDesc('version_no')->get())->resolve(),
            'invoice' => $invoice ? (new TransportInvoiceResource($invoice))->resolve() : null,
            'defaultPeriod' => ['start' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'end' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ]);
    }

    public function store(TransportSettlementComputeRequest $request, TransportSettlementService $settlements): RedirectResponse
    {
        $data = $request->validated();
        $provider = Provider::query()->findOrFail($data['provider_id']);
        $contract = TransportContract::query()->findOrFail($data['transport_contract_id']);
        $invoice = isset($data['transport_invoice_id']) ? TransportInvoice::query()->find($data['transport_invoice_id']) : null;

        try {
            $settlement = $settlements->compute($provider, $contract, Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), $request->user(), $invoice, null, $request);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['period_start' => $e->getMessage()]);
        }

        return to_route('transport.settlements.show', $settlement)->with('flash', ['type' => 'success', 'message' => __('transport.settlement_computed')]);
    }

    public function show(Request $request, TransportSettlement $settlement, TransportSettlementService $settlements, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-settlements.view'), 403);
        $this->guardScope($request, $settlement, $scope);
        $settlement->load(['provider', 'contract', 'invoice', 'adjustments', 'computedBy', 'reviewedBy', 'approvedBy']);
        $routes = TransportRoute::query()->whereIn('id', collect($settlement->basis['by_route'] ?? [])->pluck('route_id')->all())->get(['id', 'route_code', 'name_en', 'name_am'])->keyBy('id');

        return Inertia::render('Transport/Settlements/Show', [
            'settlement' => (new TransportSettlementResource($settlement))->resolve(),
            'reconciliation' => $settlements->reconciliation($settlement),
            'routes' => $routes->values(),
            'can' => [
                'manage' => $user->can('transport-settlements.manage'),
                'approve' => $user->can('transport-settlements.approve'),
                'isComputer' => $settlement->computed_by === $user->id,
            ],
        ]);
    }

    public function recompute(Request $request, TransportSettlement $settlement, TransportSettlementService $settlements, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-settlements.manage'), 403);
        $this->guardScope($request, $settlement, $scope);

        return $this->act(fn () => $settlements->compute($settlement->provider, $settlement->contract, $settlement->period_start, $settlement->period_end, $request->user(), $settlement->invoice, $settlement, $request), __('transport.settlement_computed'));
    }

    public function transition(Request $request, TransportSettlement $settlement, TransportSettlementService $settlements, OrganizationScopeService $scope): RedirectResponse
    {
        $this->guardScope($request, $settlement, $scope);
        $data = $request->validate([
            'action' => ['required', 'in:submit,return,approve,reject,cancel,pay'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'min:3', 'max:2000'],
            'approved_amount' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_reference' => ['required_if:action,pay', 'nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $needs = in_array($data['action'], ['approve', 'reject', 'pay'], true) ? 'transport-settlements.approve' : 'transport-settlements.manage';
        abort_unless($user?->can($needs), 403);

        return $this->act(fn () => match ($data['action']) {
            'submit' => $settlements->submitForReview($settlement, $user, $request),
            'return' => $settlements->returnToDraft($settlement, $user, $request),
            'approve' => $settlements->approve($settlement, $user, $request, $data['approved_amount'] ?? null, $data['notes'] ?? null),
            'reject' => $settlements->reject($settlement, (string) $data['reason'], $user, $request),
            'cancel' => $settlements->cancel($settlement, $user, $request),
            'pay' => $settlements->recordPayment($settlement, (string) $data['payment_reference'], $user, $request),
        }, __('transport.settlement_updated'));
    }

    public function storeAdjustment(TransportAdjustmentRequest $request, TransportSettlement $settlement, TransportSettlementService $settlements, OrganizationScopeService $scope): RedirectResponse
    {
        $this->guardScope($request, $settlement, $scope);
        $data = $request->safe()->except('evidence');
        if ($request->hasFile('evidence')) {
            $data['evidence_path'] = $request->file('evidence')->store('transport/adjustments', 'local');
        }

        return $this->act(fn () => $settlements->requestAdjustment($settlement, TransportAdjustmentOrigin::OrganizationAdjustment, $data, $request->user(), $request), __('transport.adjustment_recorded'));
    }

    public function decideAdjustment(Request $request, TransportSettlement $settlement, TransportSettlementAdjustment $adjustment, TransportSettlementService $settlements, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-settlements.approve') || $request->user()?->can('transport-settlements.manage'), 403);
        abort_unless($adjustment->transport_settlement_id === $settlement->id, 404);
        $this->guardScope($request, $settlement, $scope);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->act(fn () => $settlements->decideAdjustment($adjustment, $data['decision'] === 'approve', $request->user(), $data['notes'] ?? null, $request), __('transport.adjustment_decided_ok'));
    }

    public function pdf(Request $request, TransportSettlement $settlement, TransportExportService $exports, OrganizationScopeService $scope)
    {
        abort_unless($request->user()?->can('transport-settlements.view'), 403);
        $this->guardScope($request, $settlement, $scope);

        return $exports->settlementPdf($settlement, $exports->locale($request));
    }

    /** @param  callable(): mixed  $action */
    private function act(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    private function guardScope(Request $request, TransportSettlement $settlement, OrganizationScopeService $scope): void
    {
        if ($settlement->organization_id !== null) {
            abort_unless($scope->canAccessOrganization($request->user(), $settlement->organization_id), 403);
        }
    }

    private function providers()
    {
        return Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']);
    }
}
