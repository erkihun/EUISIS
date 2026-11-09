<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            if (! Schema::hasColumn('id_cards', 'public_card_uuid')) {
                $table->uuid('public_card_uuid')->nullable()->unique();
            }
            if (! Schema::hasColumn('id_cards', 'qr_status')) {
                $table->string('qr_status', 16)->default('active');
            }
            if (! Schema::hasColumn('id_cards', 'qr_issued_at')) {
                $table->timestamp('qr_issued_at')->nullable();
            }
            if (! Schema::hasColumn('id_cards', 'qr_rotated_at')) {
                $table->timestamp('qr_rotated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            $table->dropColumn(['public_card_uuid', 'qr_status', 'qr_issued_at', 'qr_rotated_at']);
        });
    }
};
