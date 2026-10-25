<?php

declare(strict_types=1);

use App\Support\LegacyCredentialBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->dateTime('issued_at');
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

        // The same backfill core:import-from-euisis runs for the cards it copies (ADR 0012).
        LegacyCredentialBackfill::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_credentials');
    }
};
