<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which QR payload format a card was issued under.
 *
 * Purely additive metadata: no existing card_uuid, token or card number is
 * touched, and the printed QR keeps resolving to the same URL. Cards issued
 * before this column existed already carry the version 1 payload shape
 * (config('app.url')."/id-checker/{card_uuid}"), so they are backfilled to 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('id_cards', 'qr_payload_version')) {
            return;
        }

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->unsignedSmallInteger('qr_payload_version')->nullable()->after('qr_payload');
        });

        // Every card already carrying a public reference uses the v1 shape.
        DB::table('id_cards')
            ->whereNotNull('public_card_uuid')
            ->update(['qr_payload_version' => 1]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('id_cards', 'qr_payload_version')) {
            return;
        }

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->dropColumn('qr_payload_version');
        });
    }
};
