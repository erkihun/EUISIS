<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repoint client feedback from entitlement types to position services.
 *
 * Feedback is about work an officer PERFORMED, so it must reference the
 * position service delivered — not `service_types`, which catalogs what an
 * employee is entitled to receive. The original column was modelled on that
 * wrong assumption.
 *
 * `service_type_id` is dropped rather than kept nullable: no feedback rows
 * exist yet, and leaving a dead foreign key would invite future code to join
 * the entitlements catalog again by mistake.
 *
 * The snapshots make each row self-describing. A service can be renamed,
 * renumbered or deactivated long after a client rated it, and a performance
 * report that joined live would silently rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The original table indexed ['service_type_id', 'rating']. SQLite
         * refuses to drop a column an index still references, so the index goes
         * first — on Postgres the order is harmless, on SQLite it is required.
         */
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropIndex(['service_type_id', 'rating']);
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_service_feedback', 'service_type_id')) {
                $table->dropForeign(['service_type_id']);
                $table->dropColumn('service_type_id');
            }
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->foreignUuid('position_service_id')
                ->nullable()
                ->after('position_id')
                ->constrained('position_services')
                ->nullOnDelete();

            $table->string('service_no_snapshot', 40)->nullable()->after('position_service_id');
            $table->string('service_name_snapshot')->nullable()->after('service_no_snapshot');
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            // Performance reporting: by service within an organization, and by
            // employee across the services their post delivers.
            $table->index(['organization_id', 'service_no_snapshot'], 'esf_org_service_no_index');
            $table->index(['employee_id', 'service_no_snapshot'], 'esf_employee_service_no_index');
        });
    }

    public function down(): void
    {
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropIndex('esf_org_service_no_index');
            $table->dropIndex('esf_employee_service_no_index');
            $table->dropConstrainedForeignId('position_service_id');
            $table->dropColumn(['service_no_snapshot', 'service_name_snapshot']);
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->foreignUuid('service_type_id')->nullable()->constrained('service_types');
            $table->index(['service_type_id', 'rating']);
        });
    }
};
