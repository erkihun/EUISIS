<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured execution on a daily item: the sub-service, main task and task
 * standard it was recorded against, the planned values copied from that
 * standard at the time, the actual quality, and the scores the server
 * calculated.
 *
 * Additive and nullable only. Existing items keep working unchanged as
 * unstructured ("other work") records; nothing is mapped or rewritten,
 * because free text cannot be reliably mapped to a task.
 *
 * The existing `quantity` column is the actual quantity, and
 * `started_at` / `ended_at` / `duration_minutes` the start, completion and
 * time taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->foreignUuid('sub_service_id')->nullable()->after('position_service_id')
                ->constrained('position_service_sub_services')->nullOnDelete();
            $table->foreignUuid('task_id')->nullable()->after('sub_service_id')
                ->constrained('position_service_tasks')->nullOnDelete();
            // A standard that measured work is never deleted from under it.
            $table->foreignUuid('task_standard_id')->nullable()->after('task_id')
                ->constrained('position_service_task_standards')->restrictOnDelete();

            // Copied from the standard when the item was saved.
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->decimal('planned_time_minutes', 14, 4)->nullable();
            $table->decimal('planned_quality', 14, 4)->nullable();
            // Units, version, BPR reference and quality definition used.
            $table->json('standard_snapshot')->nullable();

            $table->decimal('actual_quality', 14, 4)->nullable();

            // Server-calculated. Raw values, not capped.
            $table->decimal('quantity_score', 12, 4)->nullable();
            $table->decimal('time_score', 12, 4)->nullable();
            $table->decimal('quality_score', 12, 4)->nullable();
            $table->decimal('task_score', 12, 4)->nullable();

            $table->index('sub_service_id', 'dai_sub_service_idx');
            $table->index('task_id', 'dai_task_idx');
            $table->index('task_standard_id', 'dai_task_standard_idx');
        });
    }

    public function down(): void
    {
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->dropIndex('dai_sub_service_idx');
            $table->dropIndex('dai_task_idx');
            $table->dropIndex('dai_task_standard_idx');
            $table->dropConstrainedForeignId('task_standard_id');
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('sub_service_id');
            $table->dropColumn([
                'planned_quantity', 'planned_time_minutes', 'planned_quality', 'standard_snapshot',
                'actual_quality', 'quantity_score', 'time_score', 'quality_score', 'task_score',
            ]);
        });
    }
};
