<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Assessment Form Builder (docs/assessment-form-builder.md).
 *
 * Configurable competency / behavioural / leadership assessment forms, built
 * in the UI rather than written into code. Extends EPMS: a criterion may
 * point at a catalog competency (`competencies`), and a version may use an
 * EPMS result scale (`performance_rating_scales`) for its result bands.
 *
 *   assessment_forms              the form, its type and owner
 *   assessment_form_versions      immutable once published; assessments use a version
 *   assessment_form_sections      logical sections of a version
 *   assessment_criteria           what is rated, in a section
 *   assessment_rating_options     the choices for a criterion, each with a decimal score
 *   assessment_form_target_rules  which employees a version applies to
 *   assessment_evaluator_schemes  who evaluates, how many, with what weight
 *
 * Scores and weights are NUMERIC, never float. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reference catalog, editable data: the five seeded types are not the
        // only valid ones.
        Schema::create('assessment_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assessment_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->foreignUuid('assessment_type_id')->constrained('assessment_types')->restrictOnDelete();
            // Null: a city-wide form. Otherwise owned and managed within that organization's scope.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->uuid('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'assessment_type_id'], 'af_status_type_idx');
            $table->index('organization_id', 'af_org_idx');
        });

        Schema::create('assessment_form_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('assessment_forms')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 20)->default('draft');

            // What the form was called when this version was published.
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->text('instructions_en')->nullable();
            $table->text('instructions_am')->nullable();

            // monthly | quarterly | six_month | annual | custom (informational; cycles set the dates).
            $table->string('period_type', 20)->nullable();
            $table->string('scoring_method', 40)->default('percent_of_max');
            // Configured form maximum; must equal the sum of section maxima.
            $table->decimal('max_total_score', 12, 4)->nullable();
            // Share of a larger result this form contributes, in percentage points.
            $table->decimal('overall_contribution_weight', 7, 4)->nullable();
            // EPMS result scale whose bands classify the final percentage.
            $table->foreignUuid('result_scale_id')->nullable()->constrained('performance_rating_scales')->nullOnDelete();
            $table->boolean('acknowledgement_required')->default(false);
            $table->boolean('review_required')->default(false);

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'version_no'], 'afv_form_version_unique');
            $table->index(['status', 'effective_from', 'effective_to'], 'afv_status_dates_idx');
        });

        Schema::create('assessment_form_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_version_id')->constrained('assessment_form_versions')->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Percentage weight for the weighted scoring method.
            $table->decimal('weight', 7, 4)->nullable();
            // Configured section maximum; must equal the sum of its criteria maxima.
            $table->decimal('max_score', 12, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index(['form_version_id', 'sort_order'], 'afs_version_order_idx');
        });

        Schema::create('assessment_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('section_id')->constrained('assessment_form_sections')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('assessment_form_versions')->cascadeOnDelete();
            // Optional link to the EPMS competency catalog.
            $table->foreignUuid('competency_id')->nullable()->constrained('competencies')->nullOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Highest score this criterion permits; defaults to its best option.
            $table->decimal('max_score', 12, 4)->nullable();
            // Percentage weight within the section, for the weighted method.
            $table->decimal('weight', 7, 4)->nullable();
            $table->boolean('is_required')->default(true);
            // disabled | optional | required
            $table->string('comment_mode', 10)->default('optional');
            $table->string('evidence_mode', 10)->default('disabled');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['section_id', 'sort_order'], 'ac_section_order_idx');
            $table->index('form_version_id', 'ac_version_idx');
        });

        Schema::create('assessment_rating_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('criterion_id')->constrained('assessment_criteria')->cascadeOnDelete();
            $table->string('label_en', 120)->nullable();
            $table->string('label_am', 120)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Decimal: 3, 2, 1, 0.5 …
            $table->decimal('score', 12, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['criterion_id', 'sort_order'], 'aro_criterion_order_idx');
        });

        /*
         * A version applies to an employee who matches at least one INCLUDE
         * rule and no EXCLUDE rule, on the assignment valid at the reference
         * date. Rules name master data; no admin-written expressions.
         */
        Schema::create('assessment_form_target_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_version_id')->constrained('assessment_form_versions')->cascadeOnDelete();
            // position | occupation | grade_level | job_family | organization | organization_unit | everyone
            $table->string('target_type', 30);
            // A master-data id (position, occupation, organization, unit) …
            $table->uuid('target_id')->nullable();
            // … or a value (grade level, job family).
            $table->string('target_value', 120)->nullable();
            $table->boolean('include_descendants')->default(true);
            // include | exclude
            $table->string('effect', 10)->default('include');
            // Higher wins when two forms of the same type match one employee; a tie is a conflict.
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['form_version_id', 'effect'], 'aftr_version_effect_idx');
            $table->index(['target_type', 'target_id'], 'aftr_target_idx');
        });

        Schema::create('assessment_evaluator_schemes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_version_id')->constrained('assessment_form_versions')->cascadeOnDelete();
            // self | direct_manager | peer | subordinate | committee | configured_employee
            $table->string('evaluator_type', 30);
            $table->unsignedSmallInteger('required_count')->default(1);
            // Share of the combined result, in percent; weights of a version sum to 100.
            $table->decimal('contribution_weight', 7, 4)->nullable();
            // system | manager_selected | admin_selected
            $table->string('selection_method', 30)->default('system');
            // How several evaluators of this type combine: average.
            $table->string('aggregation_method', 20)->default('average');
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['form_version_id', 'evaluator_type'], 'aes_version_type_unique');
        });

        $now = now();
        $types = [
            ['BEHAVIORAL_COMPETENCY', 'Behavioral competency', 'የባህሪ ብቃት'],
            ['TECHNICAL_COMPETENCY', 'Technical competency', 'የሙያ ብቃት'],
            ['LEADERSHIP', 'Leadership', 'አመራር'],
            ['PEER_ASSESSMENT', 'Peer assessment', 'የሥራ ባልደረባ ምዘና'],
            ['CUSTOM', 'Custom', 'ሌላ'],
        ];
        foreach ($types as $index => [$code, $en, $am]) {
            DB::table('assessment_types')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $code, 'name_en' => $en, 'name_am' => $am,
                'is_active' => true, 'sort_order' => ($index + 1) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_evaluator_schemes');
        Schema::dropIfExists('assessment_form_target_rules');
        Schema::dropIfExists('assessment_rating_options');
        Schema::dropIfExists('assessment_criteria');
        Schema::dropIfExists('assessment_form_sections');
        Schema::dropIfExists('assessment_form_versions');
        Schema::dropIfExists('assessment_forms');
        Schema::dropIfExists('assessment_types');
    }
};
