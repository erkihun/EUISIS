<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 5
 * (docs/organization-design-workforce-planning-plan.md §3.5, §5; D-1 = b):
 * unit design and responsibility mapping.
 *
 *   organization_design_studies — one restructuring / initial-structure
 *                                 study: draft → under_review → approved.
 *   unit_design_proposals       — the units a study proposes to create,
 *                                 change, move or deactivate; each accepted
 *                                 proposal becomes one organizational change
 *                                 request (the existing pipeline applies it).
 *   responsibility_types        — RESPONSIBLE / ACCOUNTABLE / … configurable;
 *                                 an exclusive type is held once per object.
 *   unit_responsibilities       — which unit (or proposed unit) holds which
 *                                 responsibility for a function, service,
 *                                 process or activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_design_studies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('reference_number', 32)->unique();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->string('study_type', 30);
            $table->text('reason')->nullable();
            $table->text('scope_notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('started_at')->nullable();
            $table->date('target_effective_date')->nullable();
            $table->foreignUuid('hierarchy_version_id')->nullable()->constrained('hierarchy_versions')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status'], 'ods_org_status_idx');
        });

        Schema::create('unit_design_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('study_id')->constrained('organization_design_studies')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('proposal_type', 20);
            $table->foreignUuid('existing_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->uuid('parent_proposed_unit_id')->nullable();
            $table->foreignUuid('parent_existing_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->foreignUuid('proposed_unit_type_id')->nullable()->constrained('organization_unit_types')->restrictOnDelete();
            $table->string('unit_type', 50)->nullable();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->text('design_rationale')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('status', 20)->default('proposed');
            $table->foreignUuid('organizational_change_request_id')->nullable()->constrained('organizational_change_requests')->nullOnDelete();
            $table->foreignUuid('resulting_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['study_id', 'parent_proposed_unit_id', 'sequence'], 'udp_study_parent_idx');
            $table->index(['existing_unit_id'], 'udp_existing_unit_idx');
        });
        Schema::table('unit_design_proposals', function (Blueprint $table): void {
            $table->foreign('parent_proposed_unit_id')->references('id')->on('unit_design_proposals')->restrictOnDelete();
        });

        Schema::create('responsibility_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Held by at most one unit per object (RACI's "accountable").
            $table->boolean('is_exclusive')->default(false);
            // Counts as covering the object in the gap report.
            $table->boolean('covers_object')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('unit_responsibilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->cascadeOnDelete();
            $table->foreignUuid('unit_design_proposal_id')->nullable()->constrained('unit_design_proposals')->cascadeOnDelete();
            // function | service | process | activity
            $table->string('object_type', 20);
            $table->uuid('object_id');
            $table->foreignUuid('responsibility_type_id')->constrained('responsibility_types')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_unit_id', 'object_type', 'object_id', 'responsibility_type_id'], 'unit_responsibilities_unit_unique');
            $table->unique(['unit_design_proposal_id', 'object_type', 'object_id', 'responsibility_type_id'], 'unit_responsibilities_proposal_unique');
            $table->index(['organization_id', 'object_type', 'object_id'], 'unit_responsibilities_object_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organization_design_studies ADD CONSTRAINT ods_type_chk CHECK (study_type IN ('initial_structure', 'restructure', 'review'))");
            DB::statement("ALTER TABLE unit_design_proposals ADD CONSTRAINT udp_type_chk CHECK (proposal_type IN ('create', 'update', 'move', 'deactivate'))");
            DB::statement('ALTER TABLE unit_design_proposals ADD CONSTRAINT udp_parent_chk CHECK (parent_proposed_unit_id IS NULL OR parent_proposed_unit_id <> id)');
            DB::statement('ALTER TABLE unit_responsibilities ADD CONSTRAINT unit_responsibilities_one_subject_chk CHECK ((organization_unit_id IS NOT NULL)::int + (unit_design_proposal_id IS NOT NULL)::int = 1)');
            DB::statement("ALTER TABLE unit_responsibilities ADD CONSTRAINT unit_responsibilities_object_chk CHECK (object_type IN ('function', 'service', 'process', 'activity'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_responsibilities');
        Schema::dropIfExists('responsibility_types');
        Schema::dropIfExists('unit_design_proposals');
        Schema::dropIfExists('organization_design_studies');
    }
};
