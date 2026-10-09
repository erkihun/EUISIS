<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('system_settings', 'options')) {
                $table->json('options')->nullable()->after('description_am');
            }

            if (! Schema::hasColumn('system_settings', 'validation_rules')) {
                $table->json('validation_rules')->nullable()->after('options');
            }

            if (! Schema::hasColumn('system_settings', 'default_value')) {
                $table->text('default_value')->nullable()->after('validation_rules');
            }

            if (! Schema::hasColumn('system_settings', 'is_system')) {
                $table->boolean('is_system')->default(true)->after('is_encrypted');
            }

            if (! Schema::hasColumn('system_settings', 'is_required')) {
                $table->boolean('is_required')->default(false)->after('is_system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            foreach (['options', 'validation_rules', 'default_value', 'is_system', 'is_required'] as $column) {
                if (Schema::hasColumn('system_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
