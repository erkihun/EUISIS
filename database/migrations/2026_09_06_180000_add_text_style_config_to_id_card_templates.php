<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every text role on the card is styled per template, so the four earlier
 * label/content columns collapse into one JSON document keyed by side and role.
 */
return new class extends Migration
{
    private const LEGACY = [
        'front_label_style' => ['front', 'label'],
        'front_content_style' => ['front', 'value'],
        'back_label_style' => ['back', 'label'],
        'back_content_style' => ['back', 'value'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('id_card_templates', 'text_style_config')) {
            Schema::table('id_card_templates', function (Blueprint $table): void {
                $table->json('text_style_config')->nullable();
            });
        }

        $legacy = array_keys(array_filter(
            self::LEGACY,
            fn (array $target, string $column): bool => Schema::hasColumn('id_card_templates', $column),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($legacy !== []) {
            foreach (DB::table('id_card_templates')->select(['id', ...$legacy])->cursor() as $row) {
                $config = [];
                foreach ($legacy as $column) {
                    $value = json_decode((string) ($row->{$column} ?? ''), true);
                    if (is_array($value) && $value !== []) {
                        [$side, $role] = self::LEGACY[$column];
                        $config[$side][$role] = $value;
                    }
                }
                if ($config !== []) {
                    DB::table('id_card_templates')->where('id', $row->id)
                        ->update(['text_style_config' => json_encode($config)]);
                }
            }

            Schema::table('id_card_templates', function (Blueprint $table) use ($legacy): void {
                $table->dropColumn($legacy);
            });
        }
    }

    public function down(): void
    {
        Schema::table('id_card_templates', function (Blueprint $table): void {
            foreach (array_keys(self::LEGACY) as $column) {
                if (! Schema::hasColumn('id_card_templates', $column)) {
                    $table->json($column)->nullable();
                }
            }
        });

        foreach (DB::table('id_card_templates')->select(['id', 'text_style_config'])->cursor() as $row) {
            $config = json_decode((string) ($row->text_style_config ?? ''), true);
            if (! is_array($config)) {
                continue;
            }
            $values = [];
            foreach (self::LEGACY as $column => [$side, $role]) {
                $values[$column] = isset($config[$side][$role]) ? json_encode($config[$side][$role]) : null;
            }
            DB::table('id_card_templates')->where('id', $row->id)->update($values);
        }

        Schema::table('id_card_templates', function (Blueprint $table): void {
            $table->dropColumn('text_style_config');
        });
    }
};
