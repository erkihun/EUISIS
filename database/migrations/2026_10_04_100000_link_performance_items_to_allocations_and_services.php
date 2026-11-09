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
        Schema::table('performance_objectives', function (Blueprint $table): void {
            $table->foreignUuid('strategic_goal_allocation_id')->nullable()->constrained('strategic_goal_allocations')->restrictOnDelete();
            $table->foreignUuid('position_service_id')->nullable()->constrained('position_services')->restrictOnDelete();
            $table->index('strategic_goal_allocation_id', 'po_allocation_idx');
            $table->index('position_service_id', 'po_position_service_idx');
        });
        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->unsignedInteger('version_no')->default(1);
            $table->foreignUuid('supersedes_goal_id')->nullable()->constrained('strategic_goals')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->dropUnique('sg_cycle_org_code_unique');
            $table->unique(['cycle_id', 'organization_id', 'code', 'version_no'], 'sg_cycle_org_code_version_unique');
        });
    }

    public function down(): void
    {
        // A rollback after goal amendments needs an explicit archival/export decision.
        // Do not collapse versions or discard their history automatically.
        if (DB::table('strategic_goals')->where('version_no', '>', 1)->exists()) {
            throw new RuntimeException('NEEDS_DECISION: strategic goal versions exist; preserve/export amendment history before rollback.');
        }
        if (DB::table('performance_objectives')->whereNotNull('strategic_goal_allocation_id')->orWhereNotNull('position_service_id')->exists()) {
            throw new RuntimeException('NEEDS_DECISION: performance links exist; preserve/export allocation and service lineage before rollback.');
        }
        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->dropUnique('sg_cycle_org_code_version_unique');
            $table->unique(['cycle_id', 'organization_id', 'code'], 'sg_cycle_org_code_unique');
            $table->dropForeign(['supersedes_goal_id']);
            $table->dropColumn(['version_no', 'supersedes_goal_id', 'change_reason']);
        });
        Schema::table('performance_objectives', function (Blueprint $table): void {
            $table->dropForeign(['strategic_goal_allocation_id']);
            $table->dropForeign(['position_service_id']);
            $table->dropIndex('po_allocation_idx');
            $table->dropIndex('po_position_service_idx');
            $table->dropColumn(['strategic_goal_allocation_id', 'position_service_id']);
        });
    }
};
