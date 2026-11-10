<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters (ADR 0037 §14): the registry office (መዝገብ ቤት) and the forwarding chain.
 *
 * - letter_registry_offices: a unit of the institution's structure designated
 *   as its registry. Nothing is typed again: the unit, its name and its staff
 *   come from the structure.
 * - letter_routings.parent_id: the step a forwarding was passed on from, so a
 *   letter can go from the registry to a head, to a team, to an officer.
 * - letter_routings.from_unit_id: the unit a step was sent from (the registry
 *   office for the first step).
 *
 * Every step is guarded: MySQL does not roll back a failed migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('letter_registry_offices')) {
            Schema::create('letter_registry_offices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'ltr_ro_org_fk')->cascadeOnDelete();
                $table->foreignUuid('organization_unit_id')->constrained('organization_units', 'id', 'ltr_ro_unit_fk')->cascadeOnDelete();
                $table->string('status', 12)->default('active');
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'ltr_ro_created_by_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_ro_updated_by_fk')->nullOnDelete();
                $table->timestamps();
                $table->unique(['organization_id', 'organization_unit_id'], 'ltr_ro_org_unit_uq');
            });
        }

        if (! Schema::hasColumn('letter_routings', 'parent_id')) {
            Schema::table('letter_routings', function (Blueprint $table): void {
                $table->foreignUuid('parent_id')->nullable()->constrained('letter_routings', 'id', 'ltr_rt_parent_fk')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('letter_routings', 'from_unit_id')) {
            Schema::table('letter_routings', function (Blueprint $table): void {
                $table->foreignUuid('from_unit_id')->nullable()->constrained('organization_units', 'id', 'ltr_rt_from_unit_fk')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('letter_routings', 'from_unit_id')) {
            Schema::table('letter_routings', function (Blueprint $table): void {
                $table->dropForeign('ltr_rt_from_unit_fk');
                $table->dropColumn('from_unit_id');
            });
        }
        if (Schema::hasColumn('letter_routings', 'parent_id')) {
            Schema::table('letter_routings', function (Blueprint $table): void {
                $table->dropForeign('ltr_rt_parent_fk');
                $table->dropColumn('parent_id');
            });
        }
        Schema::dropIfExists('letter_registry_offices');
    }
};
