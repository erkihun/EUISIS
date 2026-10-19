<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransportInvoiceResource;
use App\Http\Resources\TransportSettlementResource;
use App\Models\Provider;
use App\Models\TransportContract;
use App\Models\TransportInvoice;
use App\Services\Transport\Settlement\TransportInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Provider claims as staff see them: review, return, and the statement that reconciles each one. */
class TransportInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-invoices.viewAny'), 403);

        $invoices = TransportInvoice::query()->with(['provider', 'contract', 'settlement'])
            ->whereNot('status', 'draft')
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('submitted_at')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Invoices/Index', [
            'invoices' => TransportInvoiceResource::collection($invoices)->response()->getData(true),
            'filters' => $request->only('provider_id', 'status'),
            'providers' => Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']),
            'can' => ['review' => $user->can('transport-invoices.review'), 'settlements' => $user->can('transport-settlements.manage')],
        ]);
    }

    public function show(Request $request, TransportInvoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-invoices.view'), 403);
        $invoice->load(['provider', 'contract', 'settlement.adjustments', 'submittedBy']);

        return Inertia::render('Transport/Invoices/Show', [
            'invoice' => (new TransportInvoiceResource($invoice))->resolve() + ['submitted_by' => $invoice->submittedBy?->name],
            'settlement' => $invoice->settlement ? (new TransportSettlementResource($invoice->settlement))->resolve() : null,
            'contracts' => TransportContract::query()->where('provider_id', $invoice->provider_id)->whereIn('status', ['active', 'superseded', 'ended'])->orderByDesc('effective_from')->get(['id', 'contract_reference', 'version_no', 'settlement_model', 'effective_from', 'effective_to']),
            'can' => ['review' => $user->can('transport-invoices.review'), 'compute' => $user->can('transport-settlements.manage')],
        ]);
    }

    public function takeUnderReview(Request $request, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-invoices.review'), 403);

        return $this->act(fn () => $invoices->takeUnderReview($invoice, $request->user(), $request), __('transport.invoice_under_review'));
    }

    public function returnToProvider(Request $request, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-invoices.review'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->act(fn () => $invoices->returnToProvider($invoice, $data['reason'], $request->user(), $request), __('transport.invoice_returned'));
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
}
