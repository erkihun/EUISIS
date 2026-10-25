<?php

declare(strict_types=1);

use App\Support\PositionMasterBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 (ADR 0010): the job master data and the position lifecycle.
 *
 *  - Six masters (grades, levels, occupations, professions, employment
 *    types, position categories), each with a unique code, two names, an
 *    order and an active flag. A master value is deactivated, never deleted,
 *    once a position uses it (restrictOnDelete).
 *  - Positions reference them. The free-text grade_level is preserved: each
 *    distinct value becomes a job grade (code = the value) and the position
 *    points at it; nothing is dropped. An occupation id imported from EUISIS
 *    the Core cannot name becomes a placeholder occupation, flagged for
 *    review, so the reference survives.
 *  - An explicit lifecycle: draft, approved, suspended, abolished, archived.
 *    is_active stays and means "approved" (it is kept in step), so every rule
 *    that already reads it keeps working. Occupancy (vacant/occupied) is
 *    derived from placements and seats, never stored.
 *  - position_histories keeps every material change, with who and when.
 */
return new class extends Migration
{
    private const MASTERS = ['job_grades', 'job_levels', 'occupations', 'professions', 'employment_types', 'position_categories'];

    public function up(): void
    {
        foreach (self::MASTERS as $master) {
            Schema::create($master, function (Blueprint $table) use ($master): void {
                $table->uuid('id')->primary();
                $table->string('code', 64)->unique("{$master}_code_unique");
                $table->string('name_en', 255);
                $table->string('name_am', 255)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('needs_review')->default(false);
                $table->timestamps();
                $table->index(['is_active', 'sort_order'], "{$master}_active_idx");
            });
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->uuid('job_grade_id')->nullable();
            $table->uuid('job_level_id')->nullable();
            $table->uuid('profession_id')->nullable();
            $table->uuid('employment_type_id')->nullable();
            $table->uuid('position_category_id')->nullable();
            $table->string('lifecycle_status', 16)->default('approved');
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->index(['lifecycle_status'], 'positions_lifecycle_idx');
            $table->index(['job_grade_id'], 'positions_job_grade_idx');
        });

        Schema::create('position_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('position_id');
            $table->string('change_type', 32);
            $table->json('changes');
            $table->date('effective_from')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['position_id', 'id'], 'position_histories_position_idx');
            $table->foreign('position_id')->references('id')->on('positions')->restrictOnDelete();
        });

        PositionMasterBackfill::run();

        Schema::table('positions', function (Blueprint $table): void {
            foreach (['job_grade_id' => 'job_grades', 'job_level_id' => 'job_levels', 'occupation_id' => 'occupations', 'profession_id' => 'professions',
                'employment_type_id' => 'employment_types', 'position_category_id' => 'position_categories'] as $column => $master) {
                $table->foreign($column, "positions_{$column}_foreign")->references('id')->on($master)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            foreach (['job_grade_id', 'job_level_id', 'occupation_id', 'profession_id', 'employment_type_id', 'position_category_id'] as $column) {
                $table->dropForeign("positions_{$column}_foreign");
            }
            $table->dropIndex('positions_lifecycle_idx');
            $table->dropIndex('positions_job_grade_idx');
            $table->dropColumn(['job_grade_id', 'job_level_id', 'profession_id', 'employment_type_id', 'position_category_id', 'lifecycle_status', 'status_reason',
                'created_by', 'approved_by', 'approved_at']);
        });
        Schema::dropIfExists('position_histories');
        foreach (array_reverse(self::MASTERS) as $master) {
            Schema::dropIfExists($master);
        }
    }
};
