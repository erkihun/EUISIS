<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 2
 * (docs/organization-design-workforce-planning-plan.md §3.2): the service
 * catalog and the classifications services refer to.
 *
 *   service_classifications  — LOCAL (the city's own service classification)
 *                              and CPC rows, by scheme + revision. Reference
 *                              data: never fabricated, loaded from official
 *                              datasets; a new revision is a new set of rows.
 *   isic_activities.revision — the ISIC revision a row belongs to.
 *   services                 — an institution's services, versioned by
 *                              lineage like mandates.
 *   function_service         — which functions justify a service (many-to-many).
 *   service_isic_activity    — ISIC as an external economic-activity
 *                              reference at service level, never a task code.
 *   classification_import_batches — every import run, dry or real, with its
 *                              counts and error report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scheme', 20);
            $table->string('revision', 20);
            $table->string('code', 40);
            $table->uuid('parent_id')->nullable();
            $table->unsignedSmallInteger('level')->default(1);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['scheme', 'revision', 'code'], 'service_classifications_scheme_rev_code_unique');
            $table->index(['scheme', 'revision', 'parent_id'], 'service_classifications_parent_idx');
            $table->index(['scheme', 'is_active'], 'service_classifications_scheme_active_idx');
        });
        Schema::table('service_classifications', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('service_classifications')->nullOnDelete();
        });

        Schema::table('isic_activities', function (Blueprint $table): void {
            $table->string('revision', 20)->default('Rev.4')->after('level');
            $table->index(['revision', 'is_active'], 'isic_activities_revision_active_idx');
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // The platform module that delivers the service (cafeteria, transport…), when one does.
            $table->foreignUuid('service_type_id')->nullable()->constrained('service_types')->nullOnDelete();
            $table->foreignUuid('service_classification_id')->nullable()->constrained('service_classifications')->restrictOnDelete();
            $table->foreignUuid('cpc_classification_id')->nullable()->constrained('service_classifications')->restrictOnDelete();
            $table->string('beneficiary_type', 30)->nullable();
            $table->string('delivery_channel', 30)->nullable();
            $table->string('legal_basis')->nullable();
            $table->foreignUuid('owner_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'services_lineage_version_unique');
            $table->unique(['organization_id', 'code', 'version_no'], 'services_org_code_version_unique');
            $table->index(['organization_id', 'status'], 'services_org_status_idx');
            $table->index(['service_classification_id'], 'services_classification_idx');
        });

        Schema::create('function_service', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('function_id')->constrained('organization_functions')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['function_id', 'service_id'], 'function_service_unique');
            $table->index(['service_id'], 'function_service_service_idx');
        });

        Schema::create('service_isic_activity', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('isic_activity_id')->constrained('isic_activities')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'isic_activity_id'], 'service_isic_activity_unique');
        });

        Schema::create('classification_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dataset', 40);
            $table->string('revision', 20);
            $table->string('file_name');
            $table->string('fingerprint', 64);
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('status', 30);
            $table->json('error_report')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['dataset', 'revision', 'created_at'], 'classification_import_batches_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services ADD CONSTRAINT services_dates_chk CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE service_classifications ADD CONSTRAINT service_classifications_scheme_chk CHECK (scheme IN ('LOCAL', 'CPC'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_import_batches');
        Schema::dropIfExists('service_isic_activity');
        Schema::dropIfExists('function_service');
        Schema::dropIfExists('services');
        Schema::table('isic_activities', function (Blueprint $table): void {
            $table->dropIndex('isic_activities_revision_active_idx');
            $table->dropColumn('revision');
        });
        Schema::dropIfExists('service_classifications');
    }
};
