<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Element positions are per template, so each one stores its own layout.
 * Null keeps a template on the built-in arrangement, which is what every
 * existing template and every issued card continues to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('id_card_templates', 'layout_config')) {
            return;
        }

        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->json('layout_config')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('id_card_templates', 'layout_config')) {
            return;
        }

        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->dropColumn('layout_config');
        });
    }
};
