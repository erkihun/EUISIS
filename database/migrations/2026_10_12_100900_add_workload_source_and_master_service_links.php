<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, production gate:
 *
 *   manpower_plan_lines.workload_source_type / _reference — where the
 *     workload figures of a line came from (brief §19); the plan gate
 *     refuses an unexplained line.
 *   position_services.master_service_id — the explicit, person-made link
 *     from a legacy EPMS position service to a master service (brief §7);
 *     never set automatically. Added without a foreign key on SQLite
 *     (Laravel's table rebuild there drops partial unique indexes).
 *
 * Additive only; existing rows keep NULLs and stay operational.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::table('manpower_plan_lines', function (Blueprint $table): void {
            $table->string('workload_source_type', 40)->nullable();
            $table->string('workload_source_reference', 255)->nullable();
        });
        SchemaResume::table('position_services', function (Blueprint $table): void {
            $table->uuid('master_service_id')->nullable()->index('position_services_master_service_idx');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('master_service_id')->references('id')->on('services')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('position_services', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['master_service_id']);
            }
            $table->dropIndex('position_services_master_service_idx');
            $table->dropColumn('master_service_id');
        });
        Schema::table('manpower_plan_lines', function (Blueprint $table): void {
            $table->dropColumn(['workload_source_type', 'workload_source_reference']);
        });
    }
};
