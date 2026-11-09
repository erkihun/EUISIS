<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Assessment Oversight & Compliance (docs/assessment-oversight.md).
 *
 * Purely additive. Every step is guarded so a run interrupted on MySQL
 * (where DDL is not transactional) can simply be re-run. Foreign key and
 * index names are spelled out to stay under MySQL's 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assessment_unassessed_reasons')) {
            Schema::create('assessment_unassessed_reasons', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                // Codes are stored on assessment_records.unassessed_reason (20 chars).
                $table->string('code', 20)->unique('aur_code_unique');
                $table->string('name_en');
                $table->string('name_am')->nullable();
                // system_detected | institution_reported | approved_exception
                $table->string('source', 30);
                $table->boolean('requires_approval')->default(false);
                // Only an APPROVED exception with this flag can leave the denominator, and only when the cycle allows it.
                $table->boolean('excludes_from_denominator')->default(false);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
            $this->seedReasons();
        }

        if (! Schema::hasTable('assessment_result_band_policies')) {
            Schema::create('assessment_result_band_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50);
                $table->unsignedInteger('version_no');
                $table->string('name_en');
                $table->string('name_am')->nullable();
                // draft | active | retired. Active and retired policies are immutable.
                $table->string('status', 20)->default('draft');
                $table->decimal('range_min', 8, 4)->default(0);
                $table->decimal('range_max', 8, 4)->default(100);
                $table->boolean('requires_full_coverage')->default(true);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->dateTime('activated_at')->nullable();
                $table->foreignId('activated_by')->nullable()->constrained('users', 'id', 'arbp_activated_by_fk')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'arbp_created_by_fk')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['code', 'version_no'], 'arbp_code_version_unique');
                $table->index('status', 'arbp_status_idx');
            });
        }

        if (! Schema::hasTable('assessment_result_bands')) {
            Schema::create('assessment_result_bands', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('policy_id')->constrained('assessment_result_band_policies', 'id', 'arb_policy_fk')->restrictOnDelete();
                $table->string('code', 40);
                $table->string('label_en');
                $table->string('label_am')->nullable();
                $table->decimal('min_score', 8, 4);
                $table->decimal('max_score', 8, 4);
                $table->boolean('min_inclusive')->default(true);
                $table->boolean('max_inclusive')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['policy_id', 'code'], 'arb_policy_code_unique');
            });
        }

        if (! Schema::hasTable('assessment_cycles')) {
            Schema::create('assessment_cycles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique('acy_code_unique');
                $table->string('name_en');
                $table->string('name_am')->nullable();
                $table->foreignUuid('assessment_type_id')->constrained('assessment_types', 'id', 'acy_type_fk')->restrictOnDelete();
                // Assessment records with exactly this type and period belong to the cycle.
                $table->date('period_start');
                $table->date('period_end');
                // The assignment in force on this date places an employee in an institution.
                $table->date('reference_date');
                // draft | active | closed
                $table->string('status', 20)->default('draft');
                // open | finalized. Once finalized the denominator only moves through approved exclusions.
                $table->string('eligibility_status', 20)->default('open');
                $table->dateTime('eligibility_snapshot_at')->nullable();
                $table->dateTime('eligibility_finalized_at')->nullable();
                $table->foreignId('eligibility_finalized_by')->nullable()->constrained('users', 'id', 'acy_elig_final_by_fk')->restrictOnDelete();
                $table->foreignUuid('result_band_policy_id')->nullable()->constrained('assessment_result_band_policies', 'id', 'acy_band_policy_fk')->restrictOnDelete();
                $table->date('submission_deadline')->nullable();
                $table->date('verification_deadline')->nullable();
                // Policy switches: NULL means no approved decision yet (NEEDS_DECISION), never a guess.
                $table->json('eligible_employee_statuses')->nullable();
                $table->string('population_rule', 20)->default('all_assigned');
                $table->unsignedInteger('min_service_days')->nullable();
                $table->boolean('exclusion_reduces_denominator')->nullable();
                $table->unsignedInteger('small_group_threshold')->nullable();
                $table->unsignedInteger('reminder_days_before')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acy_created_by_fk')->restrictOnDelete();
                $table->timestamps();
                $table->index(['assessment_type_id', 'period_start', 'period_end'], 'acy_type_period_idx');
                $table->index('status', 'acy_status_idx');
            });
        }

        if (! Schema::hasTable('assessment_cycle_organizations')) {
            Schema::create('assessment_cycle_organizations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('assessment_cycle_id')->constrained('assessment_cycles', 'id', 'aco_cycle_fk')->restrictOnDelete();
                $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'aco_org_fk')->restrictOnDelete();
                // included | excluded
                $table->string('status', 20)->default('included');
                $table->dateTime('included_at');
                $table->dateTime('excluded_at')->nullable();
                $table->text('exclusion_reason')->nullable();
                // not_submitted | submitted | returned | verified | finalized | rejected | outdated
                $table->string('submission_status', 20)->default('not_submitted');
                $table->dateTime('last_reminded_at')->nullable();
                $table->foreignId('added_by')->nullable()->constrained('users', 'id', 'aco_added_by_fk')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['assessment_cycle_id', 'organization_id'], 'aco_cycle_org_unique');
                $table->index(['assessment_cycle_id', 'status', 'submission_status'], 'aco_cycle_status_idx');
            });
        }

        if (! Schema::hasTable('assessment_cycle_employee_eligibility')) {
            Schema::create('assessment_cycle_employee_eligibility', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('assessment_cycle_id')->constrained('assessment_cycles', 'id', 'acee_cycle_fk')->restrictOnDelete();
                $table->foreignUuid('employee_id')->constrained('employees', 'id', 'acee_employee_fk')->restrictOnDelete();
                $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments', 'id', 'acee_assignment_fk')->nullOnDelete();
                $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'acee_org_fk')->restrictOnDelete();
                $table->uuid('organization_unit_id')->nullable();
                $table->uuid('position_id')->nullable();
                // eligible | excluded
                $table->string('eligibility_status', 20);
                $table->foreignUuid('reason_id')->nullable()->constrained('assessment_unassessed_reasons', 'id', 'acee_reason_fk')->restrictOnDelete();
                // system_detected | approved_exception
                $table->string('reason_source', 30)->nullable();
                // matched | conflict | no_applicable_form: the target resolver's answer on the reference date.
                $table->string('form_resolution', 30);
                $table->uuid('expected_form_version_id')->nullable();
                $table->dateTime('snapshot_at');
                $table->timestamps();
                $table->unique(['assessment_cycle_id', 'employee_id'], 'acee_cycle_employee_unique');
                $table->index(['assessment_cycle_id', 'organization_id', 'eligibility_status'], 'acee_cycle_org_status_idx');
                $table->index(['assessment_cycle_id', 'organization_unit_id'], 'acee_cycle_unit_idx');
            });
        }

        if (! Schema::hasTable('assessment_exclusion_requests')) {
            Schema::create('assessment_exclusion_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('assessment_cycle_id')->constrained('assessment_cycles', 'id', 'aer_cycle_fk')->restrictOnDelete();
                $table->foreignUuid('eligibility_id')->constrained('assessment_cycle_employee_eligibility', 'id', 'aer_eligibility_fk')->restrictOnDelete();
                $table->foreignUuid('employee_id')->constrained('employees', 'id', 'aer_employee_fk')->restrictOnDelete();
                $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'aer_org_fk')->restrictOnDelete();
                $table->foreignUuid('reason_id')->constrained('assessment_unassessed_reasons', 'id', 'aer_reason_fk')->restrictOnDelete();
                $table->text('note');
                // pending | approved | rejected | withdrawn
                $table->string('status', 20)->default('pending');
                $table->foreignId('requested_by')->constrained('users', 'id', 'aer_requested_by_fk')->restrictOnDelete();
                $table->dateTime('requested_at');
                $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'aer_decided_by_fk')->restrictOnDelete();
                $table->dateTime('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamps();
                $table->index(['assessment_cycle_id', 'organization_id', 'status'], 'aer_cycle_org_status_idx');
            });
        }

        if (! Schema::hasTable('assessment_institution_submissions')) {
            Schema::create('assessment_institution_submissions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('assessment_cycle_id')->constrained('assessment_cycles', 'id', 'ais_cycle_fk')->restrictOnDelete();
                $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'ais_org_fk')->restrictOnDelete();
                $table->unsignedInteger('revision_no');
                // submitted | returned | verified | finalized | rejected | outdated
                $table->string('status', 20);
                $table->unsignedInteger('eligible_count');
                $table->unsignedInteger('excluded_count');
                $table->unsignedInteger('assessed_count');
                $table->unsignedInteger('unassessed_count');
                $table->unsignedInteger('male_eligible');
                $table->unsignedInteger('female_eligible');
                $table->unsignedInteger('unknown_eligible');
                $table->unsignedInteger('male_assessed');
                $table->unsignedInteger('female_assessed');
                $table->unsignedInteger('unknown_assessed');
                $table->decimal('coverage_percent', 7, 4)->nullable();
                $table->json('summary_snapshot');
                $table->string('source_fingerprint', 64);
                $table->foreignId('submitted_by')->constrained('users', 'id', 'ais_submitted_by_fk')->restrictOnDelete();
                $table->dateTime('submitted_at');
                $table->text('sign_off_note')->nullable();
                $table->foreignId('returned_by')->nullable()->constrained('users', 'id', 'ais_returned_by_fk')->restrictOnDelete();
                $table->dateTime('returned_at')->nullable();
                $table->text('return_reason')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'ais_verified_by_fk')->restrictOnDelete();
                $table->dateTime('verified_at')->nullable();
                $table->foreignId('finalized_by')->nullable()->constrained('users', 'id', 'ais_finalized_by_fk')->restrictOnDelete();
                $table->dateTime('finalized_at')->nullable();
                $table->dateTime('outdated_at')->nullable();
                $table->timestamps();
                $table->unique(['assessment_cycle_id', 'organization_id', 'revision_no'], 'ais_cycle_org_rev_unique');
                $table->index(['assessment_cycle_id', 'status'], 'ais_cycle_status_idx');
            });
        }

        if (! Schema::hasTable('assessment_submission_events')) {
            Schema::create('assessment_submission_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('submission_id')->constrained('assessment_institution_submissions', 'id', 'ase_submission_fk')->restrictOnDelete();
                // submitted | returned | verified | finalized | rejected | outdated
                $table->string('action', 20);
                $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'ase_actor_fk')->restrictOnDelete();
                $table->text('comment')->nullable();
                $table->dateTime('created_at');
            });
        }

        if (Schema::hasTable('assessment_records')) {
            if (! Schema::hasColumn('assessment_records', 'assessment_cycle_id')) {
                Schema::table('assessment_records', function (Blueprint $table): void {
                    $table->foreignUuid('assessment_cycle_id')->nullable()->after('id')->constrained('assessment_cycles', 'id', 'ar_cycle_fk')->restrictOnDelete();
                });
            }
            if (! Schema::hasIndex('assessment_records', 'ar_cycle_org_status_idx')) {
                Schema::table('assessment_records', function (Blueprint $table): void {
                    $table->index(['assessment_cycle_id', 'organization_id', 'status'], 'ar_cycle_org_status_idx');
                });
            }
            if (! Schema::hasIndex('assessment_records', 'ar_cycle_employee_idx')) {
                Schema::table('assessment_records', function (Blueprint $table): void {
                    $table->index(['assessment_cycle_id', 'employee_id'], 'ar_cycle_employee_idx');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assessment_records') && Schema::hasColumn('assessment_records', 'assessment_cycle_id')) {
            Schema::table('assessment_records', function (Blueprint $table): void {
                $table->dropForeign('ar_cycle_fk');
                $table->dropIndex('ar_cycle_org_status_idx');
                $table->dropIndex('ar_cycle_employee_idx');
                $table->dropColumn('assessment_cycle_id');
            });
        }
        Schema::dropIfExists('assessment_submission_events');
        Schema::dropIfExists('assessment_institution_submissions');
        Schema::dropIfExists('assessment_exclusion_requests');
        Schema::dropIfExists('assessment_cycle_employee_eligibility');
        Schema::dropIfExists('assessment_cycle_organizations');
        Schema::dropIfExists('assessment_cycles');
        Schema::dropIfExists('assessment_result_bands');
        Schema::dropIfExists('assessment_result_band_policies');
        Schema::dropIfExists('assessment_unassessed_reasons');
    }

    /**
     * Only the reasons the system itself detects, plus the two the existing
     * record workflow already records. They are editable starting data, not
     * official policy; administrators add institution reasons themselves.
     */
    private function seedReasons(): void
    {
        $now = now();
        $rows = [
            ['no_applicable_form', 'No applicable form', 'የሚመለከተው ቅጽ የለም', 'system_detected', true],
            ['employment_status', 'Not in an eligible employment status', 'ብቁ የቅጥር ሁኔታ ላይ አይደለም', 'system_detected', true],
            ['new_employee', 'Below the minimum service period', 'ከዝቅተኛው የአገልግሎት ጊዜ በታች', 'system_detected', true],
            ['illness', 'Illness', 'ህመም', 'institution_reported', false],
            ['other', 'Other', 'ሌላ', 'institution_reported', false],
        ];
        foreach ($rows as $index => [$code, $en, $am, $source, $system]) {
            DB::table('assessment_unassessed_reasons')->insert([
                'id' => (string) Str::uuid7(), 'code' => $code, 'name_en' => $en, 'name_am' => $am, 'source' => $source,
                'requires_approval' => false, 'excludes_from_denominator' => $source === 'system_detected',
                'is_system' => $system, 'is_active' => true, 'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
