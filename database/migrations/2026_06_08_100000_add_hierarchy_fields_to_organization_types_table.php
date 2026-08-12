<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('organization_types', 'level_order')) {
                $table->unsignedSmallInteger('level_order')->default(1)->after('sort_order');
            }
            if (! Schema::hasColumn('organization_types', 'category')) {
                $table->string('category', 50)->nullable()->after('level_order');
            }
            if (! Schema::hasColumn('organization_types', 'parent_allowed_types')) {
                $table->json('parent_allowed_types')->nullable()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            $cols = array_filter(
                ['parent_allowed_types', 'category', 'level_order'],
                fn (string $col): bool => Schema::hasColumn('organization_types', $col),
            );

            if ($cols !== []) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
