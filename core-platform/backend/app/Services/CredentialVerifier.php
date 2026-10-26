<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DigitalCredential;
use App\Models\IdCard;
use Illuminate\Support\Str;

/**
 * Checks a presented credential token (ADR 0010) for the public card check
 * and the verification APIs. The answer is the card and one denial code:
 *
 *   CREDENTIAL_UNKNOWN     no credential has this token
 *   CREDENTIAL_REVOKED     revoked (the card was revoked, lost, damaged or replaced, or by hand)
 *   CREDENTIAL_SUPERSEDED  a newer credential replaced it (rotation, reissue)
 *   CREDENTIAL_EXPIRED     past the credential's own expiry
 *   KEY_RETIRED            issued under a key whose grace period has ended
 *   then the card's own reasons (IdCard::invalidReason): EMPLOYEE_INACTIVE,
 *   CARD_REVOKED, CARD_REPLACED, CARD_EXPIRED, CARD_SUSPENDED, CARD_INACTIVE,
 *   CREDENTIAL_INVALID, ASSIGNMENT_INACTIVE.
 *
 * A card made before P3 that has no credential row at all is still found by
 * its public UUID (legacy QR codes); once a card has any credential, only a
 * credential token reaches it.
 */
final readonly class CredentialVerifier
{
    public function __construct(private CredentialKeys $keys) {}

    /** @return array{card: ?IdCard, credential: ?DigitalCredential, code: ?string} */
    public function check(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 16 || strlen($token) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return ['card' => null, 'credential' => null, 'code' => 'CREDENTIAL_UNKNOWN'];
        }
        $with = ['employee.currentAssignment.organization', 'employee.currentAssignment.unit', 'employee.currentAssignment.position'];
        $credential = DigitalCredential::query()->whereIn('token_hash', array_values($this->keys->hashes($token)))->first();
        if ($credential === null) {
            $legacy = Str::isUuid($token) ? IdCard::query()->with($with)->where('public_card_uuid', $token)->first() : null;
            if ($legacy !== null && ! DigitalCredential::query()->where('id_card_id', $legacy->id)->exists()) {
                return ['card' => $legacy, 'credential' => null, 'code' => $legacy->invalidReason()];
            }

            return ['card' => null, 'credential' => null, 'code' => 'CREDENTIAL_UNKNOWN'];
        }

        $card = IdCard::query()->with($with)->find($credential->id_card_id);
        $code = match (true) {
            $credential->status === DigitalCredential::REVOKED => 'CREDENTIAL_REVOKED',
            $credential->status === DigitalCredential::SUPERSEDED => 'CREDENTIAL_SUPERSEDED',
            $credential->status !== DigitalCredential::ACTIVE => 'CREDENTIAL_INVALID',
            $credential->expires_at !== null && ! $credential->expires_at->isFuture() => 'CREDENTIAL_EXPIRED',
            ! $this->keys->accepts($credential->key_version) => 'KEY_RETIRED',
            $card === null => 'CREDENTIAL_UNKNOWN',
            default => $card->invalidReason(),
        };
        if ($code === null) {
            DigitalCredential::query()->whereKey($credential->id)->increment('verification_count', 1, ['last_verified_at' => now()]);
        }

        return ['card' => $card, 'credential' => $credential, 'code' => $code];
    }
}
