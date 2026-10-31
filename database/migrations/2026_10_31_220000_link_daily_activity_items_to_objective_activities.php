<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A day's work can name the main / detailed activity of the KPI's objective it
 * was done for (ADR 0019), so time spent and completion roll up per activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->foreignUuid('objective_activity_id')->nullable()->after('employee_performance_item_id')
                ->constrained('performance_objective_activities')->nullOnDelete();
            $table->index('objective_activity_id', 'dai_objective_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->dropForeign(['objective_activity_id']);
            $table->dropIndex('dai_objective_activity_idx');
            $table->dropColumn('objective_activity_id');
        });
    }
};
