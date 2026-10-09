<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Models\IdCard;
use App\Services\IdCards\CardQrPayloadService;

class GenerateCardTokenAction
{
    public function __construct(
        private readonly CardQrPayloadService $qrPayloadService,
    ) {}

    /**
     * Generate a secure random token for server-side QR verification.
     *
     * Also seeds the stable public_card_uuid (once only) — the permanent
     * reference printed on the physical card. The public UUID never changes
     * when services are added; it changes only on card replacement.
     *
     * Stores:
     *  - token_hash   : SHA-256 of raw token (server-side verification)
     *  - qr_payload   : encrypted "<card_uuid>|<raw_token>" (legacy compat)
     *  - public_card_uuid: stable opaque UUID used in the printed QR URL
     */
    public function execute(IdCard $card): string
    {
        $rawToken = bin2hex(random_bytes(32));

        $card->update([
            'token_hash'            => hash('sha256', $rawToken),
            'token_version'         => ($card->token_version ?? 0) + 1,
            'token_last_rotated_at' => now(),
            'qr_payload'            => $card->id . '|' . $rawToken,
        ]);

        // Seed the stable public reference. Idempotent — never overwrites existing UUID.
        $this->qrPayloadService->ensurePublicReference($card);

        return $rawToken;
    }
}
