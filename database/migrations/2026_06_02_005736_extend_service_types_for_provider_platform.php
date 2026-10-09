<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_types', 'description_en')) {
                $table->text('description_en')->nullable()->after('name_am');
            }

            if (! Schema::hasColumn('service_types', 'description_am')) {
                $table->text('description_am')->nullable()->after('description_en');
            }

            if (! Schema::hasColumn('service_types', 'module_key')) {
                $table->string('module_key')->nullable()->after('description_am');
            }

            if (! Schema::hasColumn('service_types', 'route_prefix')) {
                $table->string('route_prefix')->nullable()->after('module_key');
            }

            if (! Schema::hasColumn('service_types', 'icon')) {
                $table->string('icon')->nullable()->after('route_prefix');
            }

            if (! Schema::hasColumn('service_types', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
        });

        $now = now();

        foreach ([
            ['cafeteria', 'Cafeteria Service', 'cafeteria', 'provider.portal.cafeteria.dashboard', 'utensils', 10],
            ['transport', 'Transport Service', 'transport', null, 'bus', 20],
            ['health', 'Health Service', 'health', null, 'heart-pulse', 30],
            ['consumer_association', 'Consumer Association Service', 'consumer_association', null, 'shopping-basket', 40],
            ['insurance', 'Insurance Service', 'insurance', null, 'shield-check', 50],
            ['training', 'Training Service', 'training', null, 'graduation-cap', 60],
        ] as [$code, $name, $moduleKey, $routePrefix, $icon, $sortOrder]) {
            $payload = [
                'name_en' => $name,
                'module_key' => $moduleKey,
                'route_prefix' => $routePrefix,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'updated_at' => $now,
            ];

            if (DB::table('service_types')->where('code', $code)->exists()) {
                DB::table('service_types')->where('code', $code)->update($payload);
            } else {
                DB::table('service_types')->insert([
                    'id' => (string) Str::uuid7(),
                    'code' => $code,
                    'name_en' => $name,
                    'module_key' => $moduleKey,
                    'route_prefix' => $routePrefix,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            foreach (['sort_order', 'icon', 'route_prefix', 'module_key', 'description_am', 'description_en'] as $column) {
                if (Schema::hasColumn('service_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
