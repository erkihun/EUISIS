<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Transport\TransportSettings;
use Illuminate\Foundation\Http\FormRequest;

class TransportScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transport-scan.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
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
