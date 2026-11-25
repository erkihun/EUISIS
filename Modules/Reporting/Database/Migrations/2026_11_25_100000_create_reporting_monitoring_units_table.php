<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work unit that follows up reports in an institution: an existing unit
 * of the institution's structure, designated for the purpose. One per
 * institution. Nothing is created in the organization structure; the ids are
 * references to the Core's tables, held without foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reporting_monitoring_units')) {
            return;
        }
        Schema::create('reporting_monitoring_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique('rpt_mon_unit_org_uq');
            $table->uuid('organization_unit_id')->index('rpt_mon_unit_unit_ix');
            $table->string('unit_code', 60)->nullable();
            $table->string('unit_name_en', 255)->nullable();
            $table->string('unit_name_am', 255)->nullable();
            $table->unsignedBigInteger('designated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_monitoring_units');
    }
};
