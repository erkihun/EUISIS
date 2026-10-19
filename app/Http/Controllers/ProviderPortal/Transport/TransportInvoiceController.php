<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderPortal\ProviderTransportInvoiceRequest;
use App\Http\Resources\TransportContractResource;
use App\Http\Resources\TransportInvoiceResource;
use App\Http\Resources\TransportSettlementResource;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\TransportContract;
use App\Models\TransportInvoice;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\Settlement\TransportInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The provider's own invoices: drafted, submitted, withdrawn while still
 * open, and read next to the statement the city computed. Nothing here
 * changes a verified or approved figure.
 */
class TransportInvoiceController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        $invoices = TransportInvoice::query()->with(['contract', 'settlement'])->where('provider_id', $provider->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('period_start')->latest('created_at')->paginate(25)->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Invoices/Index', [
            'invoices' => TransportInvoiceResource::collection($invoices)->response()->getData(true),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Invoices/Create', $this->formPayload($this->provider($request, $context)));
    }

    public function store(ProviderTransportInvoiceRequest $request, ProviderPortalContext $context, TransportInvoiceService $invoices): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        $data = $request->safe()->except('attachment');
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('transport/invoices/'.$provider->id, 'local');
        }

        try {
            $invoice = $invoices->draft($provider, $data, $this->providerUser());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['period_start' => $e->getMessage()]);
        }

        return to_route('provider.portal.transport.invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => __('transport.invoice_saved')]);
    }

    public function show(Request $request, ProviderPortalContext $context, TransportInvoice $invoice): Response
    {
        $this->assertOwn($request, $context, $invoice);
        $invoice->load(['contract', 'settlement.adjustments']);

        return Inertia::render('ProviderPortal/Transport/Invoices/Show', [
            'invoice' => (new TransportInvoiceResource($invoice))->resolve(),
            'settlement' => $invoice->settlement ? (new TransportSettlementResource($invoice->settlement))->resolve() : null,
        ]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportInvoice $invoice): Response
    {
        $provider = $this->assertOwn($request, $context, $invoice);
        abort_unless($invoice->status->isProviderEditable(), 403, __('transport.invoice_not_editable'));

        return Inertia::render('ProviderPortal/Transport/Invoices/Edit', [
            'invoice' => (new TransportInvoiceResource($invoice->load('contract')))->resolve(),
            ...$this->formPayload($provider),
        ]);
    }

    public function update(ProviderTransportInvoiceRequest $request, ProviderPortalContext $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $provider = $this->assertOwn($request, $context, $invoice);
        $data = $request->safe()->except('attachment');
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('transport/invoices/'.$provider->id, 'local');
        }

        return $this->act(fn () => $invoices->update($invoice, $data, $this->providerUser()), __('transport.invoice_saved'), route('provider.portal.transport.invoices.show', $invoice));
    }

    public function submit(Request $request, ProviderPortalContext $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $this->assertOwn($request, $context, $invoice);

        return $this->act(fn () => $invoices->submit($invoice, $this->providerUser(), $request), __('transport.invoice_submitted'));
    }

    public function withdraw(Request $request, ProviderPortalContext $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $this->assertOwn($request, $context, $invoice);

        return $this->act(fn () => $invoices->withdraw($invoice, $this->providerUser()), __('transport.invoice_withdrawn'));
    }

    /** @return array<string, mixed> */
    private function formPayload(Provider $provider): array
    {
        return [
            'contracts' => TransportContractResource::collection(TransportContract::query()->where('provider_id', $provider->id)->whereIn('status', ['active', 'superseded', 'ended'])->orderByDesc('effective_from')->get())->resolve(),
            'defaultPeriod' => ['start' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'end' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ];
    }

    /** @param  callable(): mixed  $action */
    private function act(callable $action, string $message, ?string $to = null): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return ($to ? redirect()->to($to) : back())->with('flash', ['type' => 'success', 'message' => $message]);
    }

    private function providerUser(): ProviderUser
    {
        /** @var ProviderUser $user */
        $user = auth('provider')->user();

        return $user;
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }

    private function assertOwn(Request $request, ProviderPortalContext $context, TransportInvoice $invoice): Provider
    {
        $provider = $this->provider($request, $context);
        abort_if($invoice->provider_id !== $provider->id, 403, __('transport.own_data_only'));

        return $provider;
    }
}
