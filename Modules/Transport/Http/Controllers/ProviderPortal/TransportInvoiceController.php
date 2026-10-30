<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Transport\Application\Services\Settlement\TransportInvoiceService;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Domain\Models\TransportInvoice;
use Modules\Transport\Http\Requests\ProviderPortal\ProviderTransportInvoiceRequest;
use Modules\Transport\Http\Resources\TransportContractResource;
use Modules\Transport\Http\Resources\TransportInvoiceResource;
use Modules\Transport\Http\Resources\TransportSettlementResource;

/**
 * The provider's own invoices: drafted, submitted, withdrawn while still
 * open, and read next to the statement the city computed. Nothing here
 * changes a verified or approved figure.
 */
class TransportInvoiceController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        $invoices = TransportInvoice::query()->with(['contract', 'settlement'])->where('provider_id', $providerId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('period_start')->latest('created_at')->paginate(25)->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Invoices/Index', [
            'invoices' => TransportInvoiceResource::collection($invoices)->response()->getData(true),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request, ProviderPortalSession $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Invoices/Create', $this->formPayload($this->providerId($request, $context)));
    }

    public function store(ProviderTransportInvoiceRequest $request, ProviderPortalSession $context, TransportInvoiceService $invoices): RedirectResponse
    {
        $providerId = $this->providerId($request, $context);
        $data = $request->safe()->except('attachment');
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('transport/invoices/'.$providerId, 'local');
        }

        try {
            $invoice = $invoices->draft($providerId, $data, $this->providerUser());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['period_start' => $e->getMessage()]);
        }

        return to_route('provider.portal.transport.invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => __('transport.invoice_saved')]);
    }

    public function show(Request $request, ProviderPortalSession $context, TransportInvoice $invoice): Response
    {
        $this->assertOwn($request, $context, $invoice);
        $invoice->load(['contract', 'settlement.adjustments']);

        return Inertia::render('ProviderPortal/Transport/Invoices/Show', [
            'invoice' => (new TransportInvoiceResource($invoice))->resolve(),
            'settlement' => $invoice->settlement ? (new TransportSettlementResource($invoice->settlement))->resolve() : null,
        ]);
    }

    public function edit(Request $request, ProviderPortalSession $context, TransportInvoice $invoice): Response
    {
        $providerId = $this->assertOwn($request, $context, $invoice);
        abort_unless($invoice->status->isProviderEditable(), 403, __('transport.invoice_not_editable'));

        return Inertia::render('ProviderPortal/Transport/Invoices/Edit', [
            'invoice' => (new TransportInvoiceResource($invoice->load('contract')))->resolve(),
            ...$this->formPayload($providerId),
        ]);
    }

    public function update(ProviderTransportInvoiceRequest $request, ProviderPortalSession $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $providerId = $this->assertOwn($request, $context, $invoice);
        $data = $request->safe()->except('attachment');
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('transport/invoices/'.$providerId, 'local');
        }

        return $this->act(fn () => $invoices->update($invoice, $data, $this->providerUser()), __('transport.invoice_saved'), route('provider.portal.transport.invoices.show', $invoice));
    }

    public function submit(Request $request, ProviderPortalSession $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $this->assertOwn($request, $context, $invoice);

        return $this->act(fn () => $invoices->submit($invoice, $this->providerUser(), $request), __('transport.invoice_submitted'));
    }

    public function withdraw(Request $request, ProviderPortalSession $context, TransportInvoice $invoice, TransportInvoiceService $invoices): RedirectResponse
    {
        $this->assertOwn($request, $context, $invoice);

        return $this->act(fn () => $invoices->withdraw($invoice, $this->providerUser()), __('transport.invoice_withdrawn'));
    }

    /** @return array<string, mixed> */
    private function formPayload(string $providerId): array
    {
        return [
            'contracts' => TransportContractResource::collection(TransportContract::query()->where('provider_id', $providerId)->whereIn('status', ['active', 'superseded', 'ended'])->orderByDesc('effective_from')->get())->resolve(),
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

    /** The signed-in portal user's provider, as an id, when it runs transport. */
    private function providerId(Request $request, ProviderPortalSession $context): string
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $providerId;
    }

    private function assertOwn(Request $request, ProviderPortalSession $context, TransportInvoice $invoice): string
    {
        $providerId = $this->providerId($request, $context);
        abort_if($invoice->provider_id !== $providerId, 403, __('transport.own_data_only'));

        return $providerId;
    }
}
