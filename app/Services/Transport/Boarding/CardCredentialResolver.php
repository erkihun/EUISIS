<?php

declare(strict_types=1);

namespace App\Services\Transport\Boarding;

use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Data\CredentialFormat;
use App\Enums\Transport\TransportVerificationMethod;
use App\Models\IdCard;
use App\Services\Nfc\NfcCredentialService;

/**
 * One resolver for both media: a QR value (the stable card URL or its
 * UUID) or an NFC credential reference, to the ID card. Card, employee and
 * transport rules are left to the engine so the two media can never drift.
 */
final readonly class CardCredentialResolver
{
    public const NFC_REFERENCE = '/\Anfc_[a-f0-9]{64}\z/';

    public function __construct(
        private CredentialLookup $credentials,
        private NfcCredentialService $nfcCredentials,
    ) {}

    /** @return array{card: IdCard|null, reason: string|null} */
    public function resolve(TransportVerificationMethod $method, string $value): array
    {
        $value = trim($value);

        if ($method === TransportVerificationMethod::Nfc) {
            if (preg_match(self::NFC_REFERENCE, $value) !== 1) {
                return ['card' => null, 'reason' => 'nfc_credential_not_found'];
            }
            $resolved = $this->nfcCredentials->resolveForAttendedScan($value);
            if ($resolved['card'] === null) {
                return ['card' => null, 'reason' => $resolved['reason'] ?? 'nfc_credential_not_found'];
            }

            return ['card' => $this->load($resolved['card']->public_card_uuid), 'reason' => null];
        }

        // Transport accepts only the printed QR (stable card URL or UUID); the backbone parses it.
        $credential = $this->credentials->resolveScan($value, CredentialFormat::PublicReference);
        $card = $this->load($credential?->cardUuid);

        return ['card' => $card, 'reason' => $card === null ? 'card_not_found' : null];
    }

    /** Which medium a raw scan value belongs to, when the caller did not say. */
    public static function methodFor(string $value): TransportVerificationMethod
    {
        return preg_match(self::NFC_REFERENCE, trim($value)) === 1 ? TransportVerificationMethod::Nfc : TransportVerificationMethod::Qr;
    }

    private function load(?string $publicUuid): ?IdCard
    {
        if ($publicUuid === null) {
            return null;
        }

        // A revoked or replaced card still resolves: the engine then refuses it
        // with its own reason, and the refusal is evidence too.
        return IdCard::query()
            ->with(['employee.currentAssignment.organization'])
            ->where('public_card_uuid', $publicUuid)
            ->first();
    }
}
