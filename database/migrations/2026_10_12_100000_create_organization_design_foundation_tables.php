<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 1
 * (docs/organization-design-workforce-planning-plan.md): the legal root of an
 * institution's design.
 *
 *   Legal instrument type (catalog)
 *   Institution mandate  — versioned by lineage_key + version_no; a published
 *                          version is immutable and is superseded by the next
 *   Power                — what the mandate empowers the institution to do
 *   Function             — a tree (parent_function_id, materialised path)
 *   Status history       — immutable record of every lifecycle transition in
 *                          this domain, shared by later phases
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_instrument_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('institution_mandates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('code', 40);
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->foreignUuid('legal_instrument_type_id')->constrained('legal_instrument_types')->restrictOnDelete();
            $table->string('legal_instrument_number', 100);
            $table->string('legal_reference')->nullable();
            $table->string('article_reference')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'institution_mandates_lineage_version_unique');
            $table->unique(['organization_id', 'code', 'version_no'], 'institution_mandates_org_code_version_unique');
            $table->index(['organization_id', 'status'], 'institution_mandates_org_status_idx');
            $table->index(['organization_id', 'effective_from'], 'institution_mandates_org_effective_idx');
        });

        Schema::create('organization_powers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('mandate_id')->constrained('institution_mandates')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('legal_reference')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mandate_id', 'code'], 'organization_powers_mandate_code_unique');
            $table->index(['organization_id', 'status'], 'organization_powers_org_status_idx');
        });

        Schema::create('organization_functions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('mandate_id')->constrained('institution_mandates')->cascadeOnDelete();
            $table->foreignUuid('power_id')->nullable()->constrained('organization_powers')->nullOnDelete();
            $table->uuid('parent_function_id')->nullable();
            $table->string('code', 40);
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('legal_reference')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            // Ancestor ids joined by '/', ending in this row's id: one query reads a subtree.
            $table->string('path', 1200);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version_no')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'organization_functions_org_code_unique');
            $table->index(['organization_id', 'parent_function_id', 'sequence'], 'organization_functions_org_parent_idx');
            $table->index(['mandate_id'], 'organization_functions_mandate_idx');
            $table->index('path', 'organization_functions_path_idx');
        });

        // The self-reference is added once the primary key exists (PostgreSQL rejects it inline).
        Schema::table('organization_functions', function (Blueprint $table): void {
            $table->foreign('parent_function_id')->references('id')->on('organization_functions')->restrictOnDelete();
        });

        Schema::create('organization_design_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('entity_type', 80);
            $table->uuid('entity_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id', 'created_at'], 'od_status_history_entity_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE institution_mandates ADD CONSTRAINT institution_mandates_dates_chk CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE organization_powers ADD CONSTRAINT organization_powers_dates_chk CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE organization_functions ADD CONSTRAINT organization_functions_dates_chk CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE organization_functions ADD CONSTRAINT organization_functions_not_own_parent_chk CHECK (parent_function_id IS NULL OR parent_function_id <> id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_design_status_history');
        Schema::dropIfExists('organization_functions');
        Schema::dropIfExists('organization_powers');
        Schema::dropIfExists('institution_mandates');
        Schema::dropIfExists('legal_instrument_types');
    }
};
