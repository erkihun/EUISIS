<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Transport\Http\Requests\Concerns\TransportProviderRequest;

/** Registering a scanner device for a provider (admin); the portal may only view and revoke. */
class StoreTransportDeviceRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return ! $this->onPortal() && ($this->user()?->can('transport-devices.create') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => $this->providerIdRules(),
            'transport_vehicle_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'device_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_.]+$/', $this->uniqueForProvider('transport_devices', 'device_code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'software_version' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        return null;
    }
}
