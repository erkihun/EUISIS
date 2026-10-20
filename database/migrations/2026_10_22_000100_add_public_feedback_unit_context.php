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
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->boolean('is_public_feedback_selectable')->default(false)->after('status');
            $table->index(['organization_id', 'is_public_feedback_selectable', 'status'], 'ou_public_feedback_lookup_idx');
        });

        // These service offices were already exposed by the preceding public
        // feedback flow. Preserve that narrow public surface; all other units
        // remain opt-in.
        DB::table('organization_units')
            ->where('unit_type', 'office')
            ->orWhereNotNull('institution_office_id')
            ->update(['is_public_feedback_selectable' => true]);

        // What the visitor saw, frozen at submission: a later rename, merge or
        // archive of the unit never changes what an old submission says.
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->string('organization_unit_name_snapshot', 255)->nullable()->after('organization_unit_id');
            $table->string('organization_unit_name_am_snapshot', 255)->nullable()->after('organization_unit_name_snapshot');
            $table->string('organization_unit_type_snapshot', 100)->nullable()->after('organization_unit_name_am_snapshot');
            // SELECTED is a real unit; GENERAL is an explicit organization-level answer.
            $table->string('unit_selection_mode', 20)->nullable()->after('organization_unit_type_snapshot');
        });

        // A routing rule may narrow itself to one relevant work unit. The rule's
        // handler stays a separate choice: the unit a submission concerns is not
        // the unit (or person) that handles it.
        Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->uuid('organization_unit_id')->nullable()->after('category_id');
            $table->foreign('organization_unit_id', 'pfrr_unit_fk')->references('id')->on('organization_units')->nullOnDelete();
            $table->index(['organization_unit_id'], 'pfrr_unit_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('public_feedback_routing_rules', 'organization_unit_id')) {
            Schema::table('public_feedback_routing_rules', function (Blueprint $table): void {
                $table->dropForeign('pfrr_unit_fk');
                $table->dropIndex('pfrr_unit_idx');
                $table->dropColumn('organization_unit_id');
            });
        }

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropColumn(array_values(array_filter(
                ['organization_unit_name_snapshot', 'organization_unit_name_am_snapshot', 'organization_unit_type_snapshot', 'unit_selection_mode'],
                fn (string $column): bool => Schema::hasColumn('employee_service_feedback', $column),
            )));
        });

        Schema::table('organization_units', function (Blueprint $table): void {
            $table->dropIndex('ou_public_feedback_lookup_idx');
            $table->dropColumn('is_public_feedback_selectable');
        });
    }
};
