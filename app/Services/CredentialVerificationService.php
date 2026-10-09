<?php

namespace App\Services;

use App\Models\IdCard;
use App\Services\IdCards\CardQrPayloadService;
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
            $uuid = app(CardQrPayloadService::class)->resolvePublicUuidFromScanValue($payload['credential'] ?? '');
            $card = $uuid ? IdCard::with('employee')->where('public_card_uuid', $uuid)->first() : null;
            $result = app(EmployeeServiceEligibilityService::class)->check($card?->employee, $card, $payload['service_type'] ?? 'verification');

            return ['valid' => $result['eligible'], 'eligible' => null, 'reason_code' => $result['reason_code'], 'card' => $card];
        }

        return ['valid' => false, 'eligible' => false, 'reason_code' => 'CREDENTIAL_TYPE_UNSUPPORTED'];
    }
}
