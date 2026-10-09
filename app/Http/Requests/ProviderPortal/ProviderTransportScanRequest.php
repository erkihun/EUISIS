<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderPortal;

use App\Models\ProviderUser;
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
            'scan_nonce' => ['required', 'uuid'],
            'transport_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'transport_trip_id' => ['nullable', 'uuid', 'exists:transport_trips,id'],
            'scanned_at' => ['nullable', 'date'],
        ];
    }
}
