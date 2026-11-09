<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\Data\PortalActor;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\Settlement\TransportSettlementService;
use Modules\Transport\Application\Services\TransportExportService;
use Modules\Transport\Domain\Enums\TransportAdjustmentOrigin;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportSettlement;
use Modules\Transport\Http\Requests\TransportAdjustmentRequest;
use Modules\Transport\Http\Resources\TransportSettlementResource;

/**
 * Statements as the provider sees them: its own, once submitted for review
 * or beyond (a draft is the city's working copy). The provider may dispute
 * a line until the statement is final; it never edits the statement.
 */
class TransportSettlementController extends Controller
{
    private const VISIBLE = ['under_review', 'approved', 'paid', 'rejected'];

    public function index(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        $settlements = TransportSettlement::query()->with('invoice')->where('provider_id', $providerId)->whereIn('status', self::VISIBLE)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('period_start')->latest('created_at')->paginate(25)->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Settlements/Index', [
            'settlements' => TransportSettlementResource::collection($settlements)->response()->getData(true),
            'filters' => $request->only('status'),
            'statuses' => self::VISIBLE,
        ]);
    }

    public function show(Request $request, ProviderPortalSession $context, TransportSettlement $settlement, TransportSettlementService $settlements): Response
    {
        $this->assertOwn($request, $context, $settlement);
        $settlement->load(['invoice', 'adjustments']);
        $routes = TransportRoute::query()->whereIn('id', collect($settlement->basis['by_route'] ?? [])->pluck('route_id')->all())->get(['id', 'route_code', 'name_en', 'name_am']);

        return Inertia::render('ProviderPortal/Transport/Settlements/Show', [
            'settlement' => (new TransportSettlementResource($settlement))->resolve(),
            'reconciliation' => $settlements->reconciliation($settlement),
            'routes' => $routes,
            'canDispute' => ! $settlement->status->isFinal() && app(ProviderPortalSession::class)->can(request(), 'provider.transport.invoices.manage'),
        ]);
    }

    public function dispute(TransportAdjustmentRequest $request, ProviderPortalSession $context, TransportSettlement $settlement, TransportSettlementService $settlements): RedirectResponse
    {
        $providerId = $this->assertOwn($request, $context, $settlement);
        $data = $request->safe()->except('evidence');
        if ($request->hasFile('evidence')) {
            $data['evidence_path'] = $request->file('evidence')->store('transport/disputes/'.$providerId, 'local');
        }

        try {
            $settlements->requestAdjustment($settlement, TransportAdjustmentOrigin::ProviderDispute, $data, $this->providerUser(), $request);
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.dispute_recorded')]);
    }

    public function pdf(Request $request, ProviderPortalSession $context, TransportSettlement $settlement, TransportExportService $exports)
    {
        $this->assertOwn($request, $context, $settlement);

        return $exports->settlementPdf($settlement, $exports->locale($request));
    }

    /** The signed-in portal user, recorded as the one acting. */
    private function providerUser(): PortalActor
    {
        $actor = app(ProviderPortalSession::class)->actor(request());
        abort_if($actor === null, 403);

        return $actor;
    }

    /** The signed-in portal user's provider, as an id, when it runs transport. */
    private function providerId(Request $request, ProviderPortalSession $context): string
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $providerId;
    }

    private function assertOwn(Request $request, ProviderPortalSession $context, TransportSettlement $settlement): string
    {
        $providerId = $this->providerId($request, $context);
        abort_if($settlement->provider_id !== $providerId, 403, __('transport.own_data_only'));
        // A draft is the city's working copy; the provider sees a statement once it is under review.
        abort_unless(in_array($settlement->status->value, self::VISIBLE, true), 404);

        return $providerId;
    }
}
