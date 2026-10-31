<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Http\Requests\StoreTransportRouteStopRequest;

/** Stops live on the route edit page (admin and portal); the request decides who may edit the route. */
class TransportRouteStopController extends Controller
{
    public function store(StoreTransportRouteStopRequest $request, TransportRoute $route): RedirectResponse
    {
        $route->stops()->create($request->validated() + ['created_by' => $request->user('web')?->id]);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.stop_saved')]);
    }

    public function update(StoreTransportRouteStopRequest $request, TransportRoute $route, TransportRouteStop $stop): RedirectResponse
    {
        abort_unless($stop->transport_route_id === $route->id, 404);
        $stop->update($request->validated() + ['updated_by' => $request->user('web')?->id]);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.stop_saved')]);
    }

    public function destroy(Request $request, TransportRoute $route, TransportRouteStop $stop): RedirectResponse
    {
        abort_unless($stop->transport_route_id === $route->id, 404);
        abort_unless($this->mayEditRoute($request, $route), 403);
        // Soft delete: assignments that named the stop keep their history.
        $stop->delete();

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.stop_removed')]);
    }

    /** Admin: the routes.update permission; portal: the provider's own route and the routes.manage key. */
    private function mayEditRoute(Request $request, TransportRoute $route): bool
    {
        if ($request->routeIs('provider.portal.*')) {
            $providerUser = auth('provider')->user();

            return $providerUser !== null && $route->provider_id === $providerUser->provider_id
                && $providerUser->hasService('transport') && $providerUser->canUseServicePermission('provider.transport.routes.manage');
        }

        return $request->user()?->can('transport-routes.update') ?? false;
    }
}
