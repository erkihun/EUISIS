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
        Schema::create('strategic_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name_am', 500);
            $table->string('name_en', 500);
            $table->text('description_am')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('weight_percent', 7, 4);
            $table->boolean('is_shared')->default(false);
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'organization_id', 'code'], 'sg_cycle_org_code_unique');
            $table->index(['cycle_id', 'organization_id', 'status'], 'sg_cycle_org_status_idx');
        });

        Schema::create('strategic_goal_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('strategic_goal_id')->constrained('strategic_goals')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->decimal('organization_contribution_percent', 7, 4);
            $table->string('allocation_type', 20);
            $table->boolean('is_lead')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['strategic_goal_id', 'organization_unit_id'], 'sga_goal_unit_unique');
            $table->index(['organization_unit_id', 'allocation_type'], 'sga_unit_type_idx');
        });

        Schema::table('performance_objectives', function (Blueprint $table): void {
            $table->foreignUuid('strategic_goal_id')->nullable()->after('performance_plan_id')->constrained('strategic_goals')->restrictOnDelete();
            $table->decimal('absolute_weight_percent', 7, 4)->nullable()->after('weight');
            $table->decimal('local_weight_percent', 7, 4)->nullable()->after('absolute_weight_percent');
            $table->index('strategic_goal_id', 'po_strategic_goal_idx');
        });

        Schema::create('kpi_period_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('kpi_target_id')->constrained('kpi_targets')->cascadeOnDelete();
            $table->string('period_type', 16);
            $table->unsignedTinyInteger('period_number');
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('target_numerator', 18, 4)->nullable();
            $table->decimal('target_denominator', 18, 4)->nullable();
            $table->boolean('is_cumulative')->default(false);
            $table->timestamps();
            $table->unique(['kpi_target_id', 'period_type', 'period_number'], 'kpt_target_period_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE strategic_goals ADD CONSTRAINT sg_weight_range CHECK (weight_percent >= 0 AND weight_percent <= 100)');
            DB::statement('ALTER TABLE strategic_goal_allocations ADD CONSTRAINT sga_weight_range CHECK (organization_contribution_percent > 0 AND organization_contribution_percent <= 100)');
            DB::statement("ALTER TABLE kpi_period_targets ADD CONSTRAINT kpt_period_type CHECK (period_type IN ('QUARTER','MONTH'))");
            DB::statement("ALTER TABLE kpi_period_targets ADD CONSTRAINT kpt_period_number CHECK ((period_type = 'QUARTER' AND period_number BETWEEN 1 AND 4) OR (period_type = 'MONTH' AND period_number BETWEEN 1 AND 12))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_period_targets');
        Schema::table('performance_objectives', function (Blueprint $table): void {
            $table->dropForeign(['strategic_goal_id']);
            $table->dropIndex('po_strategic_goal_idx');
            $table->dropColumn(['strategic_goal_id', 'absolute_weight_percent', 'local_weight_percent']);
        });
        Schema::dropIfExists('strategic_goal_allocations');
        Schema::dropIfExists('strategic_goals');
    }
};
