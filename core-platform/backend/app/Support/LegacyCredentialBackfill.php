<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CredentialKeys;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Legacy credentials for cards that have none (ADR 0010, ADR 0012): every card
 * with a public UUID gets a credential whose token is that UUID, so QR codes
 * already printed keep verifying; a card that is no longer live gets it as
 * revoked. Repeat-safe: a card that has any credential is left alone.
 *
 * Run by the credentials migration for the cards present then, and by
 * core:import-from-euisis for the cards it copies — at go-live the schema is
 * migrated first and the cards are imported after, so the migration alone
 * would leave every imported card without a credential.
 */
final class LegacyCredentialBackfill
{
    /** @return int credentials created */
    public static function run(): int
    {
        $keys = app(CredentialKeys::class);
        $now = now();
        $created = 0;
        DB::table('id_cards')->whereNotNull('public_card_uuid')
            ->whereNotExists(fn ($q) => $q->from('digital_credentials')->whereColumn('digital_credentials.id_card_id', 'id_cards.id'))
            ->orderBy('id')->select(['id', 'employee_id', 'public_card_uuid', 'status', 'is_current', 'expires_at', 'revoked_at'])
            ->chunkById(500, function ($cards) use ($keys, $now, &$created): void {
                DB::table('digital_credentials')->insert($cards->map(function ($card) use ($keys, $now): array {
                    $live = (bool) $card->is_current && in_array($card->status, ['pending_print', 'printed', 'issued', 'active', 'suspended'], true);

                    return [
                        'id' => (string) Str::uuid7(), 'id_card_id' => $card->id, 'employee_id' => $card->employee_id, 'kind' => 'legacy_qr',
                        'token_hash' => $keys->hash((string) $card->public_card_uuid), 'token_encrypted' => Crypt::encryptString((string) $card->public_card_uuid),
                        'key_version' => $keys->current(), 'status' => $live ? 'active' : 'revoked', 'issued_at' => $now, 'expires_at' => $card->expires_at,
                        'revoked_at' => $live ? null : ($card->revoked_at ?? $now), 'revoke_reason' => $live ? null : 'card_'.$card->status,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                })->all());
                $created += $cards->count();
            });

        return $created;
    }
}
