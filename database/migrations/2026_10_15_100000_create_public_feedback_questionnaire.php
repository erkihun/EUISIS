<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The organization public feedback form, digitized from the paper
 * service-feedback questionnaire (docs/organization-public-feedback-form.md).
 * Additive only: no existing row is rewritten.
 *
 *   public_feedback_question_sets    — versions of the questionnaire. A draft
 *                                      is editable; a published version is
 *                                      frozen; publishing a newer one retires
 *                                      it. One published version per code.
 *   public_feedback_questions        — the questions of one version.
 *   public_feedback_question_options — choices of a single/multiple-choice question.
 *   public_feedback_answers          — one row per answer (one per selected
 *                                      option for multiple choice), typed
 *                                      columns so ratings aggregate in SQL.
 *
 * employee_service_feedback gains the service information of an organization
 * submission — the catalog service (or the visitor's own words when the
 * institution has no catalog), the date and a rough time — and the questionnaire version answered. All nullable: older
 * submissions simply have none. The office (service point) where it was
 * received uses the existing organization_unit_id column: for an organization
 * target it was always NULL, and it now names the unit the visitor chose.
 */
return new class extends Migration
{
    private const TABLE = 'employee_service_feedback';

    public function up(): void
    {
        Schema::create('public_feedback_question_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40);
            $table->unsignedInteger('version_no');
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['code', 'version_no'], 'pfqs_code_version_unique');
            $table->index(['code', 'status'], 'pfqs_code_status_idx');
        });

        // At most one published version of a questionnaire at a time.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE public_feedback_question_sets ADD COLUMN published_code VARCHAR(40) GENERATED ALWAYS AS (CASE WHEN status = 'published' THEN code ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX pfqs_one_published ON public_feedback_question_sets (published_code)');
        } else {
            DB::statement("CREATE UNIQUE INDEX pfqs_one_published ON public_feedback_question_sets (code) WHERE status = 'published'");
        }

        Schema::create('public_feedback_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_set_id')->constrained('public_feedback_question_sets')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('section', 20);
            $table->string('question_type', 20);
            $table->string('label_en', 300);
            $table->string('label_am', 300)->nullable();
            $table->string('help_en', 500)->nullable();
            $table->string('help_am', 500)->nullable();
            $table->boolean('is_required')->default(false);
            $table->json('submission_types');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['question_set_id', 'code'], 'pfq_set_code_unique');
            $table->index(['question_set_id', 'sort_order'], 'pfq_set_sort_idx');
        });

        Schema::create('public_feedback_question_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_id')->constrained('public_feedback_questions')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label_en', 200);
            $table->string('label_am', 200)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['question_id', 'code'], 'pfqo_question_code_unique');
        });

        Schema::create('public_feedback_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained(self::TABLE)->cascadeOnDelete();
            // Restrict: a version or question that has answers is never deleted (it is published, hence frozen).
            $table->foreignUuid('question_set_id')->constrained('public_feedback_question_sets')->restrictOnDelete();
            $table->foreignUuid('question_id')->constrained('public_feedback_questions')->restrictOnDelete();
            // The stable code and type travel with the answer, so analytics can follow one question across versions.
            $table->string('question_code', 40);
            $table->string('question_type', 20);
            $table->foreignUuid('option_id')->nullable()->constrained('public_feedback_question_options')->restrictOnDelete();
            $table->unsignedTinyInteger('rating_value')->nullable();
            $table->boolean('bool_value')->nullable();
            $table->text('text_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('submission_id', 'pfa_submission_idx');
            $table->index(['question_id', 'rating_value'], 'pfa_question_rating_idx');
            $table->index(['question_code', 'rating_value'], 'pfa_code_rating_idx');
            $table->index('option_id', 'pfa_option_idx');
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->uuid('question_set_id')->nullable();
            // The institution's catalog service (services.id), when it has one.
            $table->uuid('service_id')->nullable();
            // The visitor's own words for the service, when it is not in a catalog: unstructured, flagged by service_id IS NULL.
            $table->string('service_text', 200)->nullable();
            $table->date('service_date')->nullable();
            $table->string('service_time_band', 16)->nullable();

            $table->index('service_id', 'esf_service_idx');
            $table->index('question_set_id', 'esf_question_set_idx');
        });

        if (DB::getDriverName() !== 'sqlite') {
            // On SQLite adding a foreign key rebuilds the table; the request and the service validate the references there.
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('question_set_id')->references('id')->on('public_feedback_question_sets')->restrictOnDelete();
                $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT esf_service_time_band_chk CHECK (service_time_band IS NULL OR service_time_band IN ('morning', 'afternoon', 'evening'))");
            // Service information belongs to an organization submission; an employee's comes from the employee's post.
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT esf_org_service_context_chk CHECK (target_type = 'organization' OR (service_id IS NULL AND service_text IS NULL AND question_set_id IS NULL))");
            DB::statement("ALTER TABLE public_feedback_questions ADD CONSTRAINT pfq_type_chk CHECK (question_type IN ('rating', 'single_choice', 'multiple_choice', 'short_text', 'long_text', 'yes_no'))");
            DB::statement("ALTER TABLE public_feedback_questions ADD CONSTRAINT pfq_section_chk CHECK (section IN ('experience', 'details'))");
            DB::statement("ALTER TABLE public_feedback_question_sets ADD CONSTRAINT pfqs_status_chk CHECK (status IN ('draft', 'published', 'retired'))");
            DB::statement('ALTER TABLE public_feedback_answers ADD CONSTRAINT pfa_rating_range_chk CHECK (rating_value IS NULL OR rating_value BETWEEN 1 AND 5)');
        }

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if ($entry['name'] === 'service_feedback.manage_questions') {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('public_feedback_answers')->exists() || DB::table(self::TABLE)->whereNotNull('question_set_id')->orWhereNotNull('service_id')->orWhereNotNull('service_text')->exists()) {
            throw new RuntimeException('Public submissions carry questionnaire answers or service information; export them before rolling back the organization feedback form.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_service_time_band_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_org_service_context_chk');
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(['question_set_id']);
                $table->dropForeign(['service_id']);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex('esf_service_idx');
            $table->dropIndex('esf_question_set_idx');
            $table->dropColumn(['question_set_id', 'service_id', 'service_text', 'service_date', 'service_time_band']);
        });

        Schema::dropIfExists('public_feedback_answers');
        Schema::dropIfExists('public_feedback_question_options');
        Schema::dropIfExists('public_feedback_questions');
        Schema::dropIfExists('public_feedback_question_sets');
    }
};
