<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-template seal and signature artwork.
 *
 * Both were previously outside the template: the seal came from the single
 * global `general.seal` setting, so every template printed the same one, and
 * the signature had no image at all — only a dashed line. Storing them here
 * lets each template carry its own, positioned by the `seal` and `signature`
 * layout boxes that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->string('seal_path')->nullable()->after('back_background_path');
            $table->string('signature_path')->nullable()->after('seal_path');
        });
    }

    public function down(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->dropColumn(['seal_path', 'signature_path']);
        });
    }
};
