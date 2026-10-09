<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The back face can carry the employee photo as a security watermark, with its
 * own opacity, contrast and fit.
 *
 * Null keeps the photo off, which is what every existing template and issued
 * card continues to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('id_card_templates', 'back_photo_config')) {
            return;
        }

        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->json('back_photo_config')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('id_card_templates', 'back_photo_config')) {
            return;
        }

        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->dropColumn('back_photo_config');
        });
    }
};
