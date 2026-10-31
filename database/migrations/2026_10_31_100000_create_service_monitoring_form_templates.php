<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service Monitoring form templates (docs/service-monitoring.md §Form templates):
 * administrator-built, versioned forms assigned to organization types, and
 * the report's link to the version it was started on plus its answers to
 * the template's additional sections.
 *
 * Additive: a report without a template is the official form, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_monitoring_form_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Shared by every version of one template.
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('name_am', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();
            // draft | published | superseded | archived
            $table->string('status', 20)->default('draft');
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_tpl_created_by_fk')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users', 'id', 'sm_tpl_published_by_fk')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version'], 'sm_tpl_code_version_unique');
            $table->index(['status', 'code'], 'sm_tpl_status_code_idx');
        });

        Schema::create('service_monitoring_form_template_types', function (Blueprint $table): void {
            $table->foreignUuid('form_template_id')->constrained('service_monitoring_form_templates', 'id', 'sm_tpl_types_tpl_fk')->cascadeOnDelete();
            $table->foreignUuid('organization_type_id')->constrained('organization_types', 'id', 'sm_tpl_types_type_fk')->cascadeOnDelete();
            $table->primary(['form_template_id', 'organization_type_id'], 'sm_tpl_types_pk');
            $table->index('organization_type_id', 'sm_tpl_types_type_idx');
        });

        Schema::table('service_monitoring_submissions', function (Blueprint $table): void {
            $table->foreignUuid('form_template_id')->nullable()->after('form_version')
                ->constrained('service_monitoring_form_templates', 'id', 'sm_sub_template_fk')->restrictOnDelete();
            $table->json('custom_data')->nullable()->after('special_support_narrative');
        });

        // SQLite cannot add a foreign key in place, so the table above is rebuilt, and the
        // rebuild re-creates its partial unique indexes without their WHERE: "one current
        // revision" would then refuse a correction's revision n+1. Put them back as they were.
        if (DB::getDriverName() === 'sqlite') {
            foreach (['sm_sub_one_current_uq' => 'is_current = 1', 'sm_sub_one_accepted_uq' => "status = 'accepted'"] as $name => $where) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
                DB::statement("CREATE UNIQUE INDEX {$name} ON service_monitoring_submissions (period_id, participant_id) WHERE {$where}");
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_monitoring_submissions', function (Blueprint $table): void {
            $table->dropForeign('sm_sub_template_fk');
            $table->dropColumn(['form_template_id', 'custom_data']);
        });
        Schema::dropIfExists('service_monitoring_form_template_types');
        Schema::dropIfExists('service_monitoring_form_templates');
    }
};
