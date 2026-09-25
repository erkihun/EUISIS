<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportRouteRequest;
use App\Http\Requests\UpdateTransportRouteRequest;
use App\Http\Resources\TransportRouteResource;
use App\Models\Provider;
use App\Models\TransportRoute;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportRouteController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        return Inertia::render('ProviderPortal/Transport/Routes/Index', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        $this->provider($request, $context);
        abort_unless(auth('provider')->user()?->canUseServicePermission('provider.transport.routes.manage') ?? false, 403);

        return Inertia::render('ProviderPortal/Transport/Routes/Create');
    }

    public function store(StoreTransportRouteRequest $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $this->provider($request, $context);

        TransportRoute::query()->create(array_merge($request->validated(), ['provider_id' => $provider->id]));

        return to_route('provider.portal.transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_created')]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportRoute $route): Response
    {
        $this->assertOwnRoute($request, $context, $route);

        return Inertia::render('ProviderPortal/Transport/Routes/Edit', [
            'route' => (new TransportRouteResource($route))->resolve(),
        ]);
    }

    public function update(UpdateTransportRouteRequest $request, ProviderPortalContext $context, TransportRoute $route): RedirectResponse
    {
        $this->assertOwnRoute($request, $context, $route);
        $route->update($request->validated());

        return to_route('provider.portal.transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_updated')]);
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }

    private function assertOwnRoute(Request $request, ProviderPortalContext $context, TransportRoute $route): void
    {
        abort_if($route->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));
    }
}
