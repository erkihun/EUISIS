<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A work unit inside an institution can owe a report of its own.
 *
 * Additive: two nullable references and the unit's name as it was when the
 * obligation was published. Existing rows keep NULL and stay institution or
 * personal reports. The unit id is a reference to the Core's unit, held
 * without a foreign key (the module does not own that table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporting_schedule_targets', function (Blueprint $table): void {
            $table->uuid('organization_unit_id')->nullable()->after('organization_id');
        });

        Schema::table('reporting_obligations', function (Blueprint $table): void {
            $table->uuid('organization_unit_id')->nullable()->after('organization_id');
            $table->string('unit_code', 60)->nullable()->after('organization_name_am');
            $table->string('unit_name_en', 255)->nullable()->after('unit_code');
            $table->string('unit_name_am', 255)->nullable()->after('unit_name_en');
            $table->index(['organization_id', 'organization_unit_id'], 'rpt_obl_org_unit_ix');
        });
    }

    public function down(): void
    {
        Schema::table('reporting_obligations', function (Blueprint $table): void {
            $table->dropIndex('rpt_obl_org_unit_ix');
            $table->dropColumn(['organization_unit_id', 'unit_code', 'unit_name_en', 'unit_name_am']);
        });

        Schema::table('reporting_schedule_targets', function (Blueprint $table): void {
            $table->dropColumn('organization_unit_id');
        });
    }
};
