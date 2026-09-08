<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\IdCard;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Manages the stable public QR reference printed on physical ID cards.
 *
 * Security contract:
 * - public_card_uuid is a safe, opaque reference — not the card's primary key.
 * - QR URL resolves to /verify/card/{public_card_uuid} (public read-only page).
 * - Services are resolved dynamically at scan time; QR never encodes service data.
 * - QR is generated once and must NOT be regenerated when services are added.
 * - QR is regenerated only on explicit rotation (security event) or card replacement.
 */
final class CardQrPayloadService
{
    /**
     * The payload shape newly issued cards are stamped with.
     *
     * A card keeps the version it was issued under for life — the QR is already
     * printed on plastic, so its meaning cannot be changed retroactively.
     */
    public function currentPayloadVersion(): int
    {
        return (int) config('security.id_card_qr_payload_version', 1);
    }

    /**
     * Ensure the card has a stable public_card_uuid.
     * Idempotent — does nothing if one already exists.
     */
    public function ensurePublicReference(IdCard $card): void
    {
        if ($card->public_card_uuid !== null) {
            // Only backfill the version marker for a card issued before this
            // metadata existed. The reference itself is never re-issued.
            if ($card->qr_payload_version === null) {
                $card->update(['qr_payload_version' => 1]);
            }

            return;
        }

        $card->update([
            'public_card_uuid' => Str::uuid()->toString(),
            'qr_payload_version' => $this->currentPayloadVersion(),
            'qr_status' => 'active',
            'qr_issued_at' => now(),
        ]);
    }

    /**
     * Build the stable QR verification URL for printing on the physical card.
     * Always uses the configured APP_URL so the link works on the real domain.
     */
    public function buildStableQrUrl(IdCard $card): string
    {
        $this->ensurePublicReference($card);

        // Points at the OTP-gated Global ID Checker. The older
        // /verify/card/{uuid} page showed the organization and card number to
        // anyone who scanned, with no consent from the card holder.
        return rtrim((string) config('app.url'), '/').'/id-checker/'.$card->public_card_uuid;
    }

    /**
     * Rotate the QR reference (security event — physically reprint the card).
     * Logs the rotation reason. Old public_card_uuid becomes invalid.
     */
    public function rotateQrReference(IdCard $card, User $actor, string $reason): void
    {
        // Rotation reprints the card, so the new reference carries whatever
        // payload shape is current.
        $card->update([
            'public_card_uuid' => Str::uuid()->toString(),
            'qr_payload_version' => $this->currentPayloadVersion(),
            'qr_status' => 'active',
            'qr_issued_at' => now(),
            'qr_rotated_at' => now(),
        ]);
    }

    /**
     * Revoke the QR reference (card revoked / lost / replaced).
     * The card's qr_status is set to 'revoked' so scans fail immediately.
     */
    public function revokeQrReference(IdCard $card): void
    {
        $card->update(['qr_status' => 'revoked']);
    }

    /**
     * Resolve a card from a raw QR scan value.
     * Accepts:
     *   - Full stable URL: https://domain/verify/card/{uuid}
     *   - Raw public UUID
     *   - Legacy URL: https://domain/id-cards/{card_primary_id}
     *   - Legacy token format: {card_id}|{raw_token}
     *
     * Returns null if the format is unrecognisable.
     */
    public function resolvePublicUuidFromScanValue(string $scanValue): ?string
    {
        $scanValue = trim($scanValue);

        // Stable URL: .../verify/card/{uuid}
        if (preg_match('#/verify/card/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $scanValue, $m)) {
            return $m[1];
        }

        // Raw UUID alone
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $scanValue)) {
            return $scanValue;
        }

        return null;
    }
}
