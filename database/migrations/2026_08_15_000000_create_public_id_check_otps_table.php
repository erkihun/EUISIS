<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for the public Global ID Checker.
 *
 * An anonymous visitor who scans a card gets nothing until the CARD HOLDER
 * approves the check by reading a code sent to their own email and phone. This
 * table holds only the hash of that code — never the code itself — so a
 * database read cannot be replayed into a successful verification.
 *
 * `ip_address` and `user_agent` describe the anonymous checker, not the
 * employee, and exist so abuse against one card can be traced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_id_check_otps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_card_id')->constrained('id_cards')->cascadeOnDelete();
            // Denormalised so a lookup by scanned QR needs no join.
            $table->uuid('card_uuid')->index();
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            // Supports "the live code for this card": newest unverified first.
            $table->index(['card_uuid', 'verified_at', 'expires_at'], 'pic_otp_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_id_check_otps');
    }
};
