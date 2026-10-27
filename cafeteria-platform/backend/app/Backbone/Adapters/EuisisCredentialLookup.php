<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Data\CardCredential;
use App\Backbone\Data\CredentialFormat;
use App\Models\IdCard;
use App\Services\Euisis\EuisisClient;
use App\Services\Euisis\EuisisMirror;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A scanned credential → the mirrored ID card, with EUISIS as the authority.
 *
 * The printed QR code carries the card's public reference (a UUID, alone or at
 * the end of the verification URL). The platform asks EUISIS to verify that
 * reference (`GET /api/v1/id-cards/verify/{uuid}`), mirrors the card, the
 * employee, their placement and organization it answers with, and then
 * evaluates the mirrored card. While EUISIS cannot be reached, a card the
 * mirror already holds (verified within the offline grace period) is still
 * accepted, so a counter keeps serving through a short outage.
 *
 * The legacy "cardId|token" format carries a secret only EUISIS can check,
 * so it is accepted only while EUISIS answers (`POST /api/v1/cards/verify`).
 */
final class EuisisCredentialLookup implements CredentialLookup
{
    public function __construct(
        private readonly EuisisClient $euisis,
        private readonly EuisisMirror $mirror,
        private readonly CardCredentialStatus $status,
    ) {}

    public function resolveScan(string $value, CredentialFormat ...$formats): ?CardCredential
    {
        $card = $this->find($value, $formats);

        return $card === null ? null : $this->status->evaluate($card);
    }

    /** @param  list<CredentialFormat>  $formats */
    private function find(string $value, array $formats): ?IdCard
    {
        if ($value === '') {
            return null;
        }

        if (in_array(CredentialFormat::PublicReference, $formats, true)) {
            $publicUuid = self::publicUuidFrom($value);
            if ($publicUuid !== null) {
                return $this->byPublicReference($publicUuid);
            }
        }

        if (in_array(CredentialFormat::Token, $formats, true) && str_contains($value, '|')) {
            return $this->byToken($value);
        }

        return null;
    }

    /** The public reference in a scan value: the UUID itself or the last path segment of the verification URL. */
    public static function publicUuidFrom(string $value): ?string
    {
        $value = trim($value);
        if (Str::isUuid($value)) {
            return strtolower($value);
        }
        if (preg_match('~([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?(?:[?#].*)?$~i', $value, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function byPublicReference(string $publicUuid): ?IdCard
    {
        $local = IdCard::query()->where('public_card_uuid', $publicUuid)->first();
        // Without an EUISIS to ask, the mirror (filled by an import) is the authority.
        if (! $this->euisis->configured()) {
            return $local;
        }
        if ($local !== null && ! $this->mirror->isStale($local)) {
            return $local;
        }

        try {
            $verified = $this->euisis->verifyCardReference($publicUuid);
            if ($verified === null) {
                // EUISIS does not know the card (or it is outside the platform's bound): the mirror must not keep serving it.
                $local?->forceFill(['status' => IdCard::REVOKED, 'qr_status' => 'revoked'])->save();

                return null;
            }

            return $this->mirror->cardFromVerification($verified);
        } catch (Throwable $e) {
            Log::warning('EUISIS verification unavailable; serving the mirrored card.', ['card' => $publicUuid, 'error' => $e->getMessage()]);
        }

        return $local !== null && $this->mirror->withinOfflineGrace($local) ? $local : null;
    }

    private function byToken(string $value): ?IdCard
    {
        [$cardId] = array_pad(explode('|', $value, 2), 2, null);
        if ($cardId === null || ! Str::isUuid($cardId) || ! $this->euisis->configured()) {
            return null;
        }
        try {
            $verified = $this->euisis->verifyScanValue($value);
        } catch (Throwable $e) {
            Log::warning('EUISIS token verification unavailable.', ['error' => $e->getMessage()]);

            return null;
        }

        return $verified === null ? null : $this->mirror->cardFromVerification($verified);
    }
}
