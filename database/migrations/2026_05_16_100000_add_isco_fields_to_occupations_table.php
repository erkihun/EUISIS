<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occupations', function (Blueprint $table): void {
            $table->string('isco_code', 10)->nullable()->after('code');
            $table->string('isco_major_group_code', 1)->nullable()->after('isco_code');
            $table->string('isco_sub_major_group_code', 2)->nullable()->after('isco_major_group_code');
            $table->string('isco_minor_group_code', 3)->nullable()->after('isco_sub_major_group_code');
            $table->string('isco_unit_group_code', 4)->nullable()->after('isco_minor_group_code');
            $table->string('skill_specialization', 255)->nullable()->after('skill_level');
        });

        // Populate isco_code from existing code where blank
        DB::table('occupations')
            ->whereNull('isco_code')
            ->update(['isco_code' => DB::raw('code')]);

        Schema::table('occupations', function (Blueprint $table): void {
            try {
                $table->dropIndex(['is_active', 'category']);
            } catch (Throwable) {
                // index may not exist; ignore
            }
            $table->dropColumn('category');
        });

        Schema::table('occupations', function (Blueprint $table): void {
            $table->unique('isco_code');
            $table->index(['isco_major_group_code']);
            $table->index(['is_active', 'isco_major_group_code']);
        });
    }

    public function down(): void
    {
        Schema::table('occupations', function (Blueprint $table): void {
            try {
                $table->dropUnique(['isco_code']);
            } catch (Throwable) {
            }
            try {
                $table->dropIndex(['isco_major_group_code']);
            } catch (Throwable) {
            }
            try {
                $table->dropIndex(['is_active', 'isco_major_group_code']);
            } catch (Throwable) {
            }

            $table->string('category', 100)->nullable();
            $table->dropColumn([
                'isco_code',
                'isco_major_group_code',
                'isco_sub_major_group_code',
                'isco_minor_group_code',
                'isco_unit_group_code',
                'skill_specialization',
            ]);
        });
    }
};
