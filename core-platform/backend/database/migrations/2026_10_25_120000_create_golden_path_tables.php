<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Golden Path master tables, owned by the Core platform.
 *
 * Generated from the EUISIS schema so every table and column keeps its name,
 * type, nullability and default: the data moves across unchanged (stable
 * UUIDs, numbers and card references) and the same core:snapshot fingerprint
 * can be compared between the two databases. Foreign keys exist only between
 * Core tables; columns that named an EUISIS user (created_by, approved_by, …)
 * stay plain references. "One current placement / one live card per employee"
 * is a partial unique index on PostgreSQL (as in EUISIS) and a unique index on
 * a generated column elsewhere: MySQL/MariaDB have no partial indexes, and
 * PostgreSQL cannot index a virtual generated column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 255);
            $table->string('prefix', 20)->nullable();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedSmallInteger('level_order')->default(1);
            $table->string('category', 50)->nullable();
            $table->longText('parent_allowed_types')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->unique(['code'], 'organization_types_code_unique');
            $table->index(['prefix'], 'organization_types_prefix_index');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_type_id');
            $table->uuid('merged_into_id')->nullable();
            $table->string('code', 255);
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->string('legal_basis_ref', 255)->nullable();
            $table->string('status', 255);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->longText('metadata')->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('branding_primary_color', 7)->nullable();
            $table->string('branding_secondary_color', 7)->nullable();
            $table->string('public_contact_phone', 32)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deletion_reason')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['code'], 'organizations_code_unique');
            $table->index(['effective_from'], 'organizations_effective_from_index');
            $table->index(['effective_to'], 'organizations_effective_to_index');
            $table->index(['status'], 'organizations_status_index');
            $table->foreign('organization_type_id')->references('id')->on('organization_types')->restrictOnDelete();
        });

        Schema::create('hierarchy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version_name', 255);
            $table->string('source_document', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 255);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approval_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['effective_from'], 'hierarchy_versions_effective_from_index');
            $table->index(['effective_to'], 'hierarchy_versions_effective_to_index');
            $table->index(['status'], 'hierarchy_versions_status_index');
            $table->unique(['version_name'], 'hierarchy_versions_version_name_unique');
        });

        Schema::create('organization_edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('hierarchy_version_id');
            $table->uuid('parent_organization_id');
            $table->uuid('child_organization_id');
            $table->string('relationship_type', 255);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['effective_from'], 'organization_edges_effective_from_index');
            $table->index(['effective_to'], 'organization_edges_effective_to_index');
            $table->index(['relationship_type'], 'organization_edges_relationship_type_index');
            $table->unique(['hierarchy_version_id', 'parent_organization_id', 'child_organization_id', 'relationship_type'], 'organization_edges_unique');
            $table->foreign('hierarchy_version_id')->references('id')->on('hierarchy_versions')->cascadeOnDelete();
            $table->foreign('parent_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('child_organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('organization_unit_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 255);
            $table->string('prefix', 64)->nullable();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->longText('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['code'], 'organization_unit_types_code_unique');
        });

        Schema::create('organization_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('parent_unit_id')->nullable();
            $table->string('unit_type', 255);
            $table->uuid('organization_unit_type_id')->nullable();
            $table->uuid('institution_office_id')->nullable();
            $table->string('code', 255);
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('status', 255)->default('active');
            $table->boolean('is_public_feedback_selectable')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('sort_order')->default(0);
            $table->longText('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deletion_reason')->nullable();
            $table->index(['institution_office_id'], 'organization_units_institution_office_id_index');
            $table->unique(['organization_id', 'code'], 'organization_units_organization_id_code_unique');
            $table->index(['organization_id'], 'organization_units_organization_id_index');
            $table->index(['parent_unit_id'], 'organization_units_parent_unit_id_index');
            $table->index(['status'], 'organization_units_status_index');
            $table->index(['unit_type'], 'organization_units_unit_type_index');
            $table->index(['organization_id', 'is_public_feedback_selectable', 'status'], 'ou_public_feedback_lookup_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('organization_unit_type_id')->references('id')->on('organization_unit_types')->nullOnDelete();
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('occupation_id')->nullable();
            $table->string('job_position_code', 255);
            $table->string('old_code', 100)->nullable();
            $table->string('title_en', 255);
            $table->string('title_am', 255)->nullable();
            $table->string('bpr_name', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('grade_level', 255)->nullable();
            $table->string('job_family', 255)->nullable();
            $table->string('code', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deletion_reason')->nullable();
            $table->unique(['code'], 'positions_code_unique');
            $table->index(['deleted_at'], 'positions_deleted_at_index');
            $table->index(['job_family', 'grade_level'], 'positions_job_family_grade_level_index');
            $table->unique(['job_position_code'], 'positions_job_position_code_unique');
            $table->index(['organization_id'], 'positions_organization_id_index');
            $table->index(['organization_id', 'is_active'], 'positions_organization_id_is_active_index');
            $table->index(['organization_unit_id'], 'positions_organization_unit_idx');
            $table->index(['organization_id', 'organization_unit_id', 'job_position_code'], 'positions_org_unit_code_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('organization_unit_id')->references('id')->on('organization_units')->nullOnDelete();
        });

        Schema::create('position_establishments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('establishment_number', 255);
            $table->uuid('organization_id');
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('position_id');
            $table->uuid('occupation_id')->nullable();
            $table->unsignedInteger('approved_slots');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 255)->default('draft');
            $table->string('approval_reference', 255)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['effective_from', 'effective_to'], 'position_establishments_effective_from_effective_to_index');
            $table->unique(['establishment_number'], 'position_establishments_establishment_number_unique');
            $table->index(['organization_id', 'status'], 'position_establishments_organization_id_status_index');
            $table->index(['position_id', 'status'], 'position_establishments_position_id_status_index');
            $table->index(['status'], 'position_establishments_status_index');
            $table->foreign('position_id')->references('id')->on('positions')->restrictOnDelete();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('employee_number', 255);
            $table->uuid('current_assignment_id')->nullable();
            $table->string('first_name', 255);
            $table->string('middle_name', 255)->nullable();
            $table->string('last_name', 255);
            $table->string('full_name', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('national_id')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('phone', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('employment_type', 32)->nullable();
            $table->string('emergency_contact_name', 255)->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->string('status', 255);
            $table->decimal('data_quality_score', 5, 2)->default(100.00);
            $table->longText('metadata')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('national_id_hash', 64)->nullable();
            $table->string('emergency_contact_relationship', 255)->nullable();
            $table->string('preferred_language', 2)->default('en');
            $table->longText('notification_preferences')->nullable();
            $table->index(['created_at'], 'employees_created_at_idx');
            $table->index(['current_assignment_id'], 'employees_current_assignment_id_index');
            $table->index(['date_of_birth'], 'employees_date_of_birth_index');
            $table->index(['email'], 'employees_email_index');
            $table->unique(['employee_number'], 'employees_employee_number_unique');
            $table->index(['full_name'], 'employees_full_name_index');
            $table->index(['name_en'], 'employees_name_en_index');
            $table->index(['national_id_hash'], 'employees_national_id_hash_index');
            $table->unique(['national_id_hash'], 'employees_national_id_hash_unique');
            $table->index(['phone'], 'employees_phone_index');
            $table->index(['status'], 'employees_status_index');
            $table->index(['updated_at'], 'employees_updated_at_idx');
        });

        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('organization_id');
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->uuid('hierarchy_version_id')->nullable();
            $table->string('assignment_status', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            if (! $this->partialIndexes()) {
                $table->uuid('current_employee_id')->nullable()->virtualAs($this->currentEmployeeExpression(self::CURRENT_PLACEMENT));
            }
            $table->uuid('job_description_id')->nullable();
            $table->index(['employee_id', 'is_current'], 'ea_employee_current_idx');
            $table->index(['organization_id', 'is_current'], 'ea_organization_current_idx');
            $table->index(['position_id'], 'ea_position_idx');
            $table->index(['assignment_status'], 'employee_assignments_assignment_status_index');
            $table->index(['effective_from'], 'employee_assignments_effective_from_index');
            $table->index(['effective_to'], 'employee_assignments_effective_to_index');
            $table->index(['is_current'], 'employee_assignments_is_current_index');
            $table->index(['job_description_id'], 'employee_assignments_job_description_idx');
            if (! $this->partialIndexes()) {
                $table->unique(['current_employee_id'], 'employee_assignments_one_current_per_employee');
            }
            $table->index(['organization_unit_id'], 'employee_assignments_organization_unit_id_index');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('organization_unit_id')->references('id')->on('organization_units')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
        });

        Schema::create('position_occupancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('position_establishment_id');
            $table->uuid('employee_id');
            $table->uuid('employee_assignment_id');
            $table->uuid('organization_id');
            $table->uuid('position_id');
            $table->date('occupied_from');
            $table->date('occupied_until')->nullable();
            $table->string('status', 255)->default('active');
            $table->string('release_reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['employee_id', 'status'], 'position_occupancies_employee_id_status_index');
            $table->index(['position_establishment_id', 'status'], 'position_occupancies_position_establishment_id_status_index');
            $table->index(['position_id', 'status'], 'position_occupancies_position_id_status_index');
            $table->index(['status'], 'position_occupancies_status_index');
            $table->foreign('position_establishment_id')->references('id')->on('position_establishments')->restrictOnDelete();
            $table->foreign('employee_assignment_id')->references('id')->on('employee_assignments')->restrictOnDelete();
        });

        Schema::create('card_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('status', 255);
            $table->string('request_type', 255)->nullable();
            $table->uuid('previous_card_id')->nullable();
            $table->text('request_reason')->nullable();
            $table->text('verification_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->index(['status'], 'card_requests_status_index');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });

        Schema::create('id_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('card_request_id')->nullable();
            $table->string('print_batch_item_id', 255)->nullable();
            $table->uuid('previous_card_id')->nullable();
            $table->uuid('card_template_id')->nullable();
            $table->string('card_number', 255);
            $table->string('status', 255);
            $table->string('token_hash', 255)->nullable();
            $table->unsignedSmallInteger('token_version')->default(1);
            $table->timestamp('token_last_rotated_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->text('notes')->nullable();
            $table->longText('display_snapshot')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->text('qr_payload')->nullable();
            $table->unsignedSmallInteger('qr_payload_version')->nullable();
            $table->uuid('public_card_uuid')->nullable();
            $table->string('qr_status', 16)->default('active');
            $table->timestamp('qr_issued_at')->nullable();
            $table->timestamp('qr_rotated_at')->nullable();
            $table->unsignedTinyInteger('qr_model')->nullable();
            $table->unsignedTinyInteger('qr_version')->nullable();
            $table->string('qr_error_correction', 1)->nullable();
            $table->string('qr_payload_hash', 64)->nullable();
            $table->boolean('reprint_required')->default(false);
            $table->longText('reprint_reasons')->nullable();
            $table->timestamp('reprint_required_at')->nullable();
            if (! $this->partialIndexes()) {
                $table->uuid('live_current_employee_id')->nullable()->virtualAs($this->currentEmployeeExpression(self::LIVE_CARD));
            }
            $table->unique(['card_number'], 'id_cards_card_number_unique');
            $table->index(['employee_id', 'is_current'], 'id_cards_employee_idx');
            $table->index(['expires_at'], 'id_cards_expires_at_index');
            $table->index(['is_current'], 'id_cards_is_current_index');
            if (! $this->partialIndexes()) {
                $table->unique(['live_current_employee_id'], 'id_cards_one_live_current_per_employee');
            }
            $table->index(['previous_card_id'], 'id_cards_previous_card_id_index');
            $table->unique(['public_card_uuid'], 'id_cards_public_card_uuid_unique');
            $table->index(['reprint_required'], 'id_cards_reprint_required_index');
            $table->index(['status'], 'id_cards_status_index');
            $table->unique(['token_hash'], 'id_cards_token_hash_unique');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });

        Schema::create('card_replacements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('old_card_id');
            $table->uuid('new_card_id');
            $table->string('reason', 255);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->foreign('old_card_id')->references('id')->on('id_cards')->restrictOnDelete();
            $table->foreign('new_card_id')->references('id')->on('id_cards')->restrictOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('current_assignment_id')->references('id')->on('employee_assignments')->nullOnDelete();
        });

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->foreign('previous_card_id')->references('id')->on('id_cards')->nullOnDelete();
        });

        if ($this->partialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX employee_assignments_one_current_per_employee ON employee_assignments (employee_id) WHERE '.$this->condition(self::CURRENT_PLACEMENT));
            DB::statement('CREATE UNIQUE INDEX id_cards_one_live_current_per_employee ON id_cards (employee_id) WHERE '.$this->condition(self::LIVE_CARD));
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('card_replacements');
        Schema::dropIfExists('id_cards');
        Schema::dropIfExists('card_requests');
        Schema::dropIfExists('position_occupancies');
        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('position_establishments');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('organization_unit_types');
        Schema::dropIfExists('organization_edges');
        Schema::dropIfExists('hierarchy_versions');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('organization_types');
        Schema::enableForeignKeyConstraints();
    }

    /** A current placement: one per employee. */
    private const CURRENT_PLACEMENT = 'current';

    /** A live current card (the EUISIS definition): one per employee; revoked, suspended and expired cards do not count. */
    private const LIVE_CARD = 'live_card';

    private function partialIndexes(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private function condition(string $rule): string
    {
        $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        return match ($rule) {
            self::CURRENT_PLACEMENT => "is_current = {$true}",
            self::LIVE_CARD => "is_current = {$true} and status in ('pending_print', 'printed', 'issued', 'active')",
        };
    }

    /** The employee id while the rule holds, else null: what the generated column carries. */
    private function currentEmployeeExpression(string $rule): string
    {
        return "case when {$this->condition($rule)} then employee_id end";
    }
};
