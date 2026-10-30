<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;

/** One stop on a route (admin pages and the portal: whoever may edit the route). */
class StoreTransportRouteStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('provider.portal.*')) {
            $providerUser = auth('provider')->user();
            $route = $this->route('route');

            return $providerUser !== null && $route instanceof TransportRoute && $route->provider_id === $providerUser->provider_id
                && $providerUser->hasService('transport') && $providerUser->canUseServicePermission('provider.transport.routes.manage');
        }

        return $this->user()?->can('transport-routes.update') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $route = $this->route('route');
        $stop = $this->route('stop');
        $routeId = $route instanceof TransportRoute ? $route->id : '';

        return [
            'stop_code' => ['required', 'string', 'max:40', Rule::unique('transport_route_stops', 'stop_code')->where('transport_route_id', $routeId)->whereNull('deleted_at')->ignore($stop instanceof TransportRouteStop ? $stop->id : null)],
            'name_en' => ['required', 'string', 'max:160'],
            'name_am' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'sequence' => ['required', 'integer', 'between:1,500'],
            'pickup_allowed' => ['required', 'boolean'],
            'dropoff_allowed' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
