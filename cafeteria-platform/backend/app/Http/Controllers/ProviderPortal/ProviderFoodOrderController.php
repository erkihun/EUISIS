<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Http\Resources\ProviderPortal\CafeteriaFoodOrderResource;
use App\Models\CafeteriaFoodOrder;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderFoodOrderController extends Controller
{
    use FormatsProviderPortalData;

    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        $orders = CafeteriaFoodOrder::query()
            ->with(['employee', 'menu'])
            ->where('cafeteria_provider_id', $provider->id)
            ->when($request->string('date')->toString(), fn ($query, string $date) => $query->whereDate('order_date', $date))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('ordered_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Cafeteria/Portal/Orders/Index', [
            ...$this->portalPayload($request, $context, $provider),
            'orders' => CafeteriaFoodOrderResource::collection($orders)->resolve(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'filters' => $request->only(['date', 'status']),
        ]);
    }

    public function show(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $order->cafeteria_provider_id !== $provider->id, 404);

        $order->load(['employee', 'menu', 'items']);

        return Inertia::render('Cafeteria/Portal/Orders/Show', [
            ...$this->portalPayload($request, $context, $provider),
            'order' => (new CafeteriaFoodOrderResource($order))->resolve(),
        ]);
    }

    public function updateStatus(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,preparing,ready,served,rejected,cancelled'],
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->setStatus($request, $order, $context, $validated['status'], $validated['cancellation_reason'] ?? null);
    }

    public function confirm(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        return $this->setStatus($request, $order, $context, 'confirmed');
    }

    public function prepare(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        return $this->setStatus($request, $order, $context, 'preparing');
    }

    public function serve(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        return $this->setStatus($request, $order, $context, 'served');
    }

    public function reject(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->setStatus($request, $order, $context, 'rejected', $validated['cancellation_reason'] ?? null);
    }

    public function cancel(Request $request, CafeteriaFoodOrder $order, ProviderPortalContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->setStatus($request, $order, $context, 'cancelled', $validated['cancellation_reason'] ?? null);
    }

    private function setStatus(
        Request $request,
        CafeteriaFoodOrder $order,
        ProviderPortalContext $context,
        string $status,
        ?string $cancellationReason = null,
    ): RedirectResponse {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null || $order->cafeteria_provider_id !== $provider->id, 404);

        if ($status === 'served' && $order->status === 'served') {
            return back()->with('flash', ['message' => __('provider-portal.order_already_served'), 'type' => 'warning']);
        }

        $order->update([
            'status' => $status,
            'served_at' => $status === 'served' ? ($order->served_at ?? now()) : $order->served_at,
            'cancellation_reason' => $cancellationReason ?? $order->cancellation_reason,
            'updated_by' => $this->staffUserId($request),
        ]);

        return back()->with('flash', ['message' => __('provider-portal.order_updated'), 'type' => 'success']);
    }
}
