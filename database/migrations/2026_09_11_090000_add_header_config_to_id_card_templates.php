<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Front-header content is per template: its own logo, and optional overrides for
 * the city and bureau names in both languages.
 *
 * Null keeps a template on the global system settings and the organization
 * logo, which is what every existing template and issued card keeps rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('id_card_templates', 'header_config')) {
                $table->json('header_config')->nullable();
            }
            // The front header carries two logo slots: the city/issuing mark on
            // the left and the organization mark on the right.
            foreach (['logo_primary_path', 'logo_secondary_path'] as $column) {
                if (! Schema::hasColumn('id_card_templates', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            foreach (['header_config', 'logo_primary_path', 'logo_secondary_path'] as $column) {
                if (Schema::hasColumn('id_card_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
