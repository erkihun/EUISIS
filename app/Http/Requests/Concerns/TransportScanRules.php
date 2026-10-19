<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Transport\TransportSettings;
use Illuminate\Validation\Rule;

/**
 * The attended-terminal scan payload, shared by the portal and admin
 * requests: a card credential (QR value or NFC reference) OR a manual
 * exception naming the employee and a reason — never both, never neither.
 */
trait TransportScanRules
{
    /** @return array<string, list<mixed>> */
    protected function scanRules(bool $manualAllowed): array
    {
        $settings = app(TransportSettings::class);

        return [
            'qr_token' => ['required_without:manual_employee_id', 'nullable', 'string', 'min:10', 'max:2048'],
            'verification_method' => ['nullable', Rule::in(['qr', 'nfc'])],
            // The replay guard; Transport › Settings may let the server make one.
            'scan_nonce' => [$settings->nonceRequired() ? 'required' : 'nullable', 'uuid'],
            'transport_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'transport_trip_id' => ['nullable', 'uuid', 'exists:transport_trips,id'],
            'transport_route_stop_id' => ['nullable', 'uuid', 'exists:transport_route_stops,id'],
            // Recorded as the device's claim only; the server clock dates the scan.
            'scanned_at' => ['nullable', 'date'],
            'manual_employee_id' => [$manualAllowed ? 'nullable' : 'prohibited', 'uuid', 'exists:employees,id', 'prohibited_with:qr_token'],
            'manual_reason' => ['required_with:manual_employee_id', 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
