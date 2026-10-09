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
        // 1. Create positions table
        Schema::create('vacancy_announcement_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vacancy_announcement_id')->constrained('vacancy_announcements')->cascadeOnDelete();
            $table->foreignUuid('position_establishment_id')->constrained('position_establishments');
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units');
            $table->foreignUuid('position_id')->constrained('positions');
            $table->unsignedInteger('vacancy_slots');
            $table->timestamps();
            $table->index('vacancy_announcement_id');
            $table->index(['organization_id', 'position_id']);
        });

        // 2. Backfill from existing announcements
        DB::table('vacancy_announcements')
            ->whereNotNull('position_establishment_id')
            ->get()
            ->each(function ($a): void {
                $est = DB::table('position_establishments')
                    ->where('id', $a->position_establishment_id)
                    ->first(['organization_unit_id']);

                DB::table('vacancy_announcement_positions')->insert([
                    'id' => (string) Str::uuid7(),
                    'vacancy_announcement_id' => $a->id,
                    'position_establishment_id' => $a->position_establishment_id,
                    'organization_id' => $a->organization_id,
                    'organization_unit_id' => $est?->organization_unit_id,
                    'position_id' => $a->position_id,
                    'vacancy_slots' => $a->vacancy_slots,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // 3. Add column to applications
        Schema::table('vacancy_applications', function (Blueprint $table): void {
            $table->foreignUuid('vacancy_announcement_position_id')
                ->nullable()
                ->after('vacancy_announcement_id')
                ->constrained('vacancy_announcement_positions');
        });

        // 4. Backfill applications (SQLite+MySQL compatible subquery)
        DB::statement(
            'UPDATE vacancy_applications SET vacancy_announcement_position_id = (SELECT id FROM vacancy_announcement_positions WHERE vacancy_announcement_positions.vacancy_announcement_id = vacancy_applications.vacancy_announcement_id LIMIT 1) WHERE vacancy_announcement_position_id IS NULL',
        );

        // 5. Fix unique constraint
        Schema::table('vacancy_applications', function (Blueprint $table): void {
            try {
                $table->dropUnique(['vacancy_announcement_id', 'employee_id']);
            } catch (Exception) {
            }
            $table->unique(['vacancy_announcement_position_id', 'employee_id'], 'va_position_employee_unique');
        });

        // 6. Drop old columns from announcements
        Schema::table('vacancy_announcements', function (Blueprint $table): void {
            try {
                $table->dropForeign(['organization_id']);
            } catch (Exception) {
            }
            try {
                $table->dropForeign(['position_id']);
            } catch (Exception) {
            }
            try {
                $table->dropForeign(['position_establishment_id']);
            } catch (Exception) {
            }
            try {
                $table->dropIndex('vacancy_announcements_organization_id_status_index');
            } catch (Exception) {
            }
            try {
                $table->dropIndex('vacancy_announcements_position_id_status_index');
            } catch (Exception) {
            }
            try {
                $table->dropIndex('va_app_window_idx');
            } catch (Exception) {
            }
            $table->dropColumn(['organization_id', 'position_id', 'position_establishment_id', 'vacancy_slots']);
        });
    }

    public function down(): void
    {
        // Restore columns on announcements
        Schema::table('vacancy_announcements', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('position_id')->nullable()->constrained('positions');
            $table->foreignUuid('position_establishment_id')->nullable()->constrained('position_establishments');
            $table->unsignedInteger('vacancy_slots')->default(1);
        });

        // Restore applications table
        Schema::table('vacancy_applications', function (Blueprint $table): void {
            try {
                $table->dropUnique('va_position_employee_unique');
            } catch (Exception) {
            }
            try {
                $table->dropForeign(['vacancy_announcement_position_id']);
            } catch (Exception) {
            }
            $table->dropColumn('vacancy_announcement_position_id');
            $table->unique(['vacancy_announcement_id', 'employee_id']);
        });

        Schema::dropIfExists('vacancy_announcement_positions');
    }
};
