<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Http\Requests\StoreTransportRouteRequest;
use Modules\Transport\Http\Requests\UpdateTransportRouteRequest;
use Modules\Transport\Http\Resources\TransportRouteResource;

class TransportRouteController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        return Inertia::render('ProviderPortal/Transport/Routes/Index', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $providerId)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalSession $context): Response
    {
        $this->providerId($request, $context);
        abort_unless(auth('provider')->user()?->canUseServicePermission('provider.transport.routes.manage') ?? false, 403);

        return Inertia::render('ProviderPortal/Transport/Routes/Create');
    }

    public function store(StoreTransportRouteRequest $request, ProviderPortalSession $context): RedirectResponse
    {
        $providerId = $this->providerId($request, $context);

        TransportRoute::query()->create(array_merge($request->validated(), ['provider_id' => $providerId]));

        return to_route('provider.portal.transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_created')]);
    }

    public function edit(Request $request, ProviderPortalSession $context, TransportRoute $route): Response
    {
        $this->assertOwnRoute($request, $context, $route);

        return Inertia::render('ProviderPortal/Transport/Routes/Edit', [
            'route' => (new TransportRouteResource($route))->resolve(),
        ]);
    }

    public function update(UpdateTransportRouteRequest $request, ProviderPortalSession $context, TransportRoute $route): RedirectResponse
    {
        $this->assertOwnRoute($request, $context, $route);
        $route->update($request->validated());

        return to_route('provider.portal.transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_updated')]);
    }

    /** The signed-in portal user's provider, as an id, when it runs transport. */
    private function providerId(Request $request, ProviderPortalSession $context): string
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $providerId;
    }

    private function assertOwnRoute(Request $request, ProviderPortalSession $context, TransportRoute $route): void
    {
        abort_if($route->provider_id !== $this->providerId($request, $context), 403, __('transport.own_data_only'));
    }
}
