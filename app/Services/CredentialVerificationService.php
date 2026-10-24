<?php

namespace App\Services;

use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Contracts\ServiceEligibility;
use App\Backbone\Data\CredentialFormat;
use App\Models\IdCard;
use App\Services\Nfc\NfcVerificationService;
use Illuminate\Http\Request;

final class CredentialVerificationService
{
    /** Internal dispatcher. NFC callers must pass through the NFC application gate. */
    public function verify(string $type, array $payload, Request $request, string $purpose = 'verify'): array
    {
        if ($type === 'nfc') {
            return app(NfcVerificationService::class)->verify($payload, $request, $purpose);
        }
        if ($type === 'qr') {
            $credential = app(CredentialLookup::class)->resolveScan((string) ($payload['credential'] ?? ''), CredentialFormat::PublicReference);
            $card = $credential === null ? null : IdCard::with('employee')->find($credential->cardRecordUuid);
            // An unknown card ('' is no card) is refused, and recorded, by the gate as before.
            $result = app(ServiceEligibility::class)->checkCard((string) ($card?->getKey() ?? ''), (string) ($payload['service_type'] ?? 'verification'));

            return ['valid' => $result->eligible, 'eligible' => null, 'reason_code' => $result->reasonCode, 'card' => $card];
        }

        return ['valid' => false, 'eligible' => false, 'reason_code' => 'CREDENTIAL_TYPE_UNSUPPORTED'];
    }
}
