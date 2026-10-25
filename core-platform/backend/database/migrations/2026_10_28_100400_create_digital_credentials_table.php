<?php

declare(strict_types=1);

use App\Services\CredentialKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P3 (ADR 0010): the credential a card's QR carries, apart from the card.
 *
 * A credential is a random token. The Core keeps its keyed hash (to find it)
 * under a numbered key, and the token itself encrypted with the application
 * key (so a reprint shows the same QR and a key change can re-hash it); the
 * QR holds only the check address and the token — no name, no number.
 *
 * Existing cards: every card with a public UUID gets a legacy credential
 * whose token is that UUID, so QR codes already printed keep verifying. A
 * card that is no longer live gets its credential as revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('id_card_id');
            $table->uuid('employee_id');
            $table->string('kind', 16)->default('qr');
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->unsignedSmallInteger('key_version');
            $table->string('status', 16)->default('active');
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoke_reason', 1000)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedInteger('verification_count')->default(0);
            $table->timestamps();
            $table->index(['id_card_id', 'status'], 'digital_credentials_card_idx');
            $table->index(['key_version', 'status'], 'digital_credentials_key_idx');
            $table->foreign('id_card_id')->references('id')->on('id_cards')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
        });

        $keys = app(CredentialKeys::class);
        $now = now();
        DB::table('id_cards')->whereNotNull('public_card_uuid')->orderBy('id')
            ->select(['id', 'employee_id', 'public_card_uuid', 'status', 'is_current', 'expires_at', 'revoked_at'])
            ->chunk(500, function ($cards) use ($keys, $now): void {
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
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_credentials');
    }
};
