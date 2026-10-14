<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderPortal;

use App\Models\ProviderUser;
use App\Services\Transport\TransportSettings;
use Illuminate\Foundation\Http\FormRequest;

class ProviderTransportScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        return ($providerUser?->hasService('transport') ?? false)
            && $providerUser->canUseServicePermission('provider.transport.scan');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'min:10'],
            // The replay guard; Transport › Settings may let the server make one.
            'scan_nonce' => [app(TransportSettings::class)->nonceRequired() ? 'required' : 'nullable', 'uuid'],
            'transport_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'transport_trip_id' => ['nullable', 'uuid', 'exists:transport_trips,id'],
            // Recorded as the device's claim only; the server clock dates the scan.
            'scanned_at' => ['nullable', 'date'],
        ];
    }
}
