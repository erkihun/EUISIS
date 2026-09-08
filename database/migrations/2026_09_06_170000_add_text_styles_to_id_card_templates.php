<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Label and value typography is per-template, so each side stores its own
     * style object. Null keeps a template on the built-in card design.
     */
    private const COLUMNS = [
        'front_label_style',
        'front_content_style',
        'back_label_style',
        'back_content_style',
    ];

    public function up(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('id_card_templates', $column)) {
                    $table->json($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            $existing = array_values(array_filter(
                self::COLUMNS,
                fn (string $column): bool => Schema::hasColumn('id_card_templates', $column),
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
