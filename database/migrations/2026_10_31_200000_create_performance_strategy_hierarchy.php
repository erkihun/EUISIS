<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The strategy hierarchy above and below a strategic goal, entered the way
 * the WLMS forms did (ADR 0019): main strategy → strategy → strategic goal →
 * objective → activity. Weights are percentage points of the organization's
 * whole plan, except activities, which share their objective's 100%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_main_strategies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->decimal('weight_percent', 7, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cycle_id', 'organization_id'], 'pms_cycle_org_idx');
        });

        Schema::create('performance_strategies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('main_strategy_id')->constrained('performance_main_strategies')->cascadeOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->decimal('weight_percent', 7, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('main_strategy_id', 'ps_main_idx');
        });

        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->foreignUuid('strategy_id')->nullable()->after('organization_id')->constrained('performance_strategies')->restrictOnDelete();
            $table->index('strategy_id', 'sg_strategy_idx');
        });

        Schema::create('performance_objective_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('objective_id')->constrained('performance_objectives')->cascadeOnDelete();
            $table->string('title', 500);
            // Share of the objective (the activities of one objective add up to 100).
            $table->decimal('weight_percent', 7, 4);
            $table->unsignedInteger('time_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('objective_id', 'poa_objective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_objective_activities');
        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->dropForeign(['strategy_id']);
            $table->dropIndex('sg_strategy_idx');
            $table->dropColumn('strategy_id');
        });
        Schema::dropIfExists('performance_strategies');
        Schema::dropIfExists('performance_main_strategies');
    }
};
