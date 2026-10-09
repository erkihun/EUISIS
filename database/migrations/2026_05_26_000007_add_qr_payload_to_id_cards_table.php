<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('id_cards', 'qr_payload')) {
            return;
        }

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->text('qr_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('id_cards', function (Blueprint $table): void {
            $table->dropColumn('qr_payload');
        });
    }
};
