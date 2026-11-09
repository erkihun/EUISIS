<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved-headcount override evidence (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-007). The calculated FTE is never overwritten; when an approved
 * headcount differs from the recommendation the line now also keeps when
 * that decision was recorded and the reference it rests on (minute,
 * letter). Additive; nothing is dropped or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manpower_plan_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('manpower_plan_lines', 'adjusted_at')) {
                $table->timestamp('adjusted_at')->nullable()->after('adjusted_by');
            }
            if (! Schema::hasColumn('manpower_plan_lines', 'adjustment_reference')) {
                $table->string('adjustment_reference')->nullable()->after('adjustment_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manpower_plan_lines', function (Blueprint $table): void {
            foreach (['adjusted_at', 'adjustment_reference'] as $column) {
                if (Schema::hasColumn('manpower_plan_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
