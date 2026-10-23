<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\CredentialLookup;
use App\Backbone\Data\CardCredential;
use App\Backbone\Data\CredentialFormat;
use App\Models\IdCard;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Str;

final class IdCardCredentialLookup implements CredentialLookup
{
    public function __construct(
        private readonly CardQrPayloadService $qrPayloads,
        private readonly CardCredentialStatus $status,
    ) {}

    public function resolveScan(string $value, CredentialFormat ...$formats): ?CardCredential
    {
        // The value is used as given; callers that accept padded input trim it first.
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
            $publicUuid = $this->qrPayloads->resolvePublicUuidFromScanValue($value);
            if ($publicUuid !== null) {
                return IdCard::query()->where('public_card_uuid', $publicUuid)->first();
            }
        }

        if (in_array(CredentialFormat::Token, $formats, true) && str_contains($value, '|')) {
            [$cardId, $rawToken] = array_pad(explode('|', $value, 2), 2, null);
            // A non-uuid id is not a card (and PostgreSQL rejects comparing it).
            if ($cardId !== null && $rawToken !== null && Str::isUuid($cardId)) {
                return IdCard::query()->whereKey($cardId)->where('token_hash', hash('sha256', $rawToken))->first();
            }
        }

        // A legacy URL carries no secret (card ids appear in admin URLs), so it
        // is refused unless an operator turns it on for a supervised reprint.
        if (in_array(CredentialFormat::LegacyCardId, $formats, true)
            && config('id_cards.accept_legacy_qr', false)
            && preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $value, $m)) {
            return IdCard::query()->whereKey($m[1])->first();
        }

        return null;
    }
}
