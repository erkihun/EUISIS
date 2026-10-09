<?php

namespace App\Services\Nfc;

use Illuminate\Http\Request;

final class NfcPayloadParser
{
    public function parse(Request $request): array
    {
        abort_if(strlen($request->getContent()) > 65536, 413);

        return $request->validate([
            'credential' => ['required', 'string', 'regex:/\Anfc_[a-f0-9]{64}\z/'],
            'terminal_id' => ['required', 'string', 'max:64'],
            'challenge' => ['nullable', 'string', 'size:64'],
            'proof' => ['nullable', 'array', 'max:32'],
            'service_type' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'uuid'],
            'usage_mode' => ['nullable', 'in:single_day,use_remaining_week'],
            'meal_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'purpose' => ['nullable', 'in:verify,eligibility,record'],
        ]);
    }

    public function context(array $payload, string $purpose): string
    {
        return hash('sha256', json_encode([
            'version' => 1,
            'purpose' => $purpose,
            'service_type' => $payload['service_type'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'usage_mode' => $payload['usage_mode'] ?? 'single_day',
            'meal_amount' => isset($payload['meal_amount']) ? number_format((float) $payload['meal_amount'], 2, '.', '') : null,
        ], JSON_THROW_ON_ERROR));
    }
}
