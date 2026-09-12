<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which QR symbol a card was rendered with.
 *
 * The symbol version can change as the verification URL changes length, so the
 * card keeps a note of what it was issued with. This is metadata only — it
 * never participates in resolving a scan, and the card's identity columns
 * (public_card_uuid, card_number) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            if (! Schema::hasColumn('id_cards', 'qr_model')) {
                $table->unsignedTinyInteger('qr_model')->nullable();
            }
            if (! Schema::hasColumn('id_cards', 'qr_version')) {
                $table->unsignedTinyInteger('qr_version')->nullable();
            }
            if (! Schema::hasColumn('id_cards', 'qr_error_correction')) {
                $table->string('qr_error_correction', 1)->nullable();
            }
            // A fingerprint of the payload, so a changed verification URL is
            // detectable without storing the URL itself a second time.
            if (! Schema::hasColumn('id_cards', 'qr_payload_hash')) {
                $table->string('qr_payload_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            foreach (['qr_model', 'qr_version', 'qr_error_correction', 'qr_payload_hash'] as $column) {
                if (Schema::hasColumn('id_cards', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
