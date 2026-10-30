<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Delivery Monitoring (docs/service-monitoring.md).
 *
 * A form is a versioned, administrator-built schema (sections → tables,
 * notes and text areas). It is assigned to organization types, never to a
 * hard-coded "sub-city" or "city" level. An institution fills one report per
 * form and reporting period; the report pins the exact form version it was
 * filled on, so publishing a new version never rewrites an old report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_monitoring_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Shared by every version of the same form.
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('name_am', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();
            // draft | published | superseded | archived
            $table->string('status', 20)->default('draft');
            $table->json('schema');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['status', 'code']);
        });

        Schema::create('service_monitoring_form_organization_type', function (Blueprint $table): void {
            $table->foreignUuid('form_id')->constrained('service_monitoring_forms')->cascadeOnDelete();
            $table->foreignUuid('organization_type_id')->constrained('organization_types')->cascadeOnDelete();
            $table->primary(['form_id', 'organization_type_id']);
            $table->index('organization_type_id');
        });

        Schema::create('service_monitoring_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Ethiopian fiscal year, e.g. 2019.
            $table->unsignedSmallInteger('fiscal_year');
            // first_quarter | half_year | nine_months | annual
            $table->string('period_type', 20);
            $table->string('name_am', 160);
            $table->string('name_en', 160)->nullable();
            $table->date('due_date')->nullable();
            // open | closed
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year', 'period_type']);
            $table->index('status');
        });

        Schema::create('service_monitoring_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // The exact version filled; form_code keeps one report per form across versions.
            $table->foreignUuid('form_id')->constrained('service_monitoring_forms')->restrictOnDelete();
            $table->string('form_code', 64);
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            // draft | submitted | returned | accepted
            $table->string('status', 20)->default('draft');
            $table->json('data');
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['form_code', 'period_id', 'organization_id'], 'sm_submissions_one_per_period');
            $table->index(['period_id', 'status']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_monitoring_submissions');
        Schema::dropIfExists('service_monitoring_periods');
        Schema::dropIfExists('service_monitoring_form_organization_type');
        Schema::dropIfExists('service_monitoring_forms');
    }
};
