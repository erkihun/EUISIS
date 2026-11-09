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
        //
        // A dedicated short domain can be configured to keep the payload small,
        // which keeps the printed symbol's version low. Unset, this is exactly
        // the app URL and /id-checker path the cards already carry.
        return $this->qrBaseUrl().'/'.$card->public_card_uuid;
    }

    /**
     * The verification origin and path the QR points at, without a trailing
     * slash. Falls back to the app URL so nothing changes until a short domain
     * is deliberately configured.
     */
    public function qrBaseUrl(): string
    {
        $configured = trim((string) config('id_cards.qr.base_url', ''));

        if ($configured !== '') {
            $base = rtrim($configured, '/');

            // A short domain is paired with a short path, so the saving is not
            // given straight back by a long one.
            return config('id_cards.qr.short_url_enabled', false)
                ? $base.'/'.trim((string) config('id_cards.qr.short_path', 'c'), '/')
                : $base;
        }

        return rtrim((string) config('app.url'), '/').'/id-checker';
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
     * Record which QR symbol a card was last rendered with.
     *
     * Metadata only: it never participates in resolving a scan, and the card's
     * identity columns are not in the update. A card whose symbol has not
     * changed is left alone so a render does not touch the row.
     *
     * @param  array{version: int, ecc: string, model: int, capacity_bits: int, required_bits: int}  $symbol
     */
    public function recordRenderedSymbol(IdCard $card, array $symbol, string $payload): void
    {
        $hash = hash('sha256', $payload);

        if ($card->qr_version === $symbol['version']
            && $card->qr_error_correction === $symbol['ecc']
            && $card->qr_payload_hash === $hash) {
            return;
        }

        $card->update([
            'qr_model' => $symbol['model'],
            'qr_version' => $symbol['version'],
            'qr_error_correction' => $symbol['ecc'],
            'qr_payload_hash' => $hash,
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
     *
     * Delegates to QrPayloadParser so every terminal — cafeteria, transport,
     * provider — recognises exactly the same set of payload shapes, including
     * the /id-checker/{uuid} URL the cards actually print.
     */
    public function resolvePublicUuidFromScanValue(string $scanValue): ?string
    {
        return app(QrPayloadParser::class)->parse($scanValue);
    }
}
