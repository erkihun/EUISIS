<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Requests\Concerns\TransportProviderRequest;

/**
 * One dispatch action on a trip (assign, start, depart, complete, cancel,
 * fail, replace_vehicle). The admin route needs the dispatch permission; the
 * portal route needs the dispatch key and the trip must be the provider's.
 */
class TransportDispatchRequest extends FormRequest
{
    use TransportProviderRequest;

    public const ACTIONS = ['assign', 'start', 'depart', 'complete', 'cancel', 'fail', 'replace_vehicle'];

    public function authorize(): bool
    {
        $trip = $this->boundTransportRecord();
        if ($this->onPortal()) {
            $providerUser = auth('provider')->user();

            return $providerUser !== null && $trip instanceof TransportTrip && $trip->provider_id === $providerUser->provider_id
                && $providerUser->hasService('transport') && $providerUser->canUseServicePermission('provider.transport.dispatch');
        }

        return $this->user()?->can('transport-trips.dispatch') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $action = (string) $this->input('action');

        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'transport_vehicle_id' => [in_array($action, ['replace_vehicle'], true) ? 'required' : 'nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'transport_driver_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_drivers')],
            'reason' => [in_array($action, ['cancel', 'fail', 'replace_vehicle'], true) ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $trip = $this->route('trip');

        return $trip instanceof Model ? $trip : null;
    }

    protected function transportProviderId(): ?string
    {
        return $this->boundTransportRecord()?->getAttribute('provider_id');
    }
}
