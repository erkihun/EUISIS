<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters (ADR 0037): one module owning every official letter and template,
 * the registry of each institution, routing, attachments and numbering.
 *
 * Conventions shared with the other module migrations: UUID keys, explicit
 * index and foreign-key names under 64 characters (the live MySQL server
 * refuses longer generated ones), DATETIME for mandatory moments, and one
 * hasTable guard per table so an interrupted deployment can be resumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('letter_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_organization_id')->nullable()->constrained('organizations', 'id', 'ltr_tpl_owner_org_fk')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title_en', 200);
            $table->string('title_am', 200)->nullable();
            $table->string('category', 20)->default('general');
            $table->string('language', 2)->default('am');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_version_no')->default(0);
            $table->string('draft_subject', 500)->nullable();
            $table->longText('draft_body')->nullable();
            $table->json('draft_placeholders')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'ltr_tpl_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_tpl_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_organization_id', 'status'], 'ltr_tpl_owner_status_idx');
            $table->index(['code'], 'ltr_tpl_code_idx');
        });

        $this->create('letter_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('letter_templates', 'id', 'ltr_tplv_template_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('subject', 500);
            $table->longText('body');
            $table->json('placeholders')->nullable();
            $table->string('change_note', 500)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users', 'id', 'ltr_tplv_published_by_fk')->nullOnDelete();
            $table->dateTime('published_at');
            $table->dateTime('created_at');
            $table->unique(['template_id', 'version_no'], 'ltr_tplv_template_version_uq');
        });

        $this->create('letters', function (Blueprint $table): void {
            $table->uuid('id');
            // Declared before this table's own foreign keys: PostgreSQL adds a
            // fluent ->primary() after them, and the self-references then have no key.
            $table->primary('id');
            $table->string('direction', 10);
            $table->string('status', 20);
            $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'ltr_org_fk')->restrictOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('organization_units', 'id', 'ltr_unit_fk')->nullOnDelete();
            $table->foreignUuid('sender_organization_id')->nullable()->constrained('organizations', 'id', 'ltr_sender_org_fk')->nullOnDelete();
            $table->foreignUuid('recipient_organization_id')->nullable()->constrained('organizations', 'id', 'ltr_recipient_org_fk')->nullOnDelete();
            $table->string('external_party_name', 255)->nullable();
            $table->string('external_party_address', 500)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('subject', 500);
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('language', 2)->default('am');
            $table->string('priority', 12)->default('normal');
            $table->date('letter_date');
            $table->foreignUuid('template_version_id')->nullable()->constrained('letter_template_versions', 'id', 'ltr_template_version_fk')->nullOnDelete();
            $table->json('template_context')->nullable();
            $table->foreignId('signatory_user_id')->nullable()->constrained('users', 'id', 'ltr_signatory_fk')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'ltr_approved_by_fk')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users', 'id', 'ltr_dispatched_by_fk')->nullOnDelete();
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignUuid('in_reply_to_letter_id')->nullable()->constrained('letters', 'id', 'ltr_in_reply_to_fk')->nullOnDelete();
            $table->foreignUuid('supersedes_letter_id')->nullable()->constrained('letters', 'id', 'ltr_supersedes_fk')->nullOnDelete();
            $table->string('regarding_type', 80)->nullable();
            $table->string('regarding_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'ltr_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status'], 'ltr_org_status_idx');
            $table->index(['regarding_type', 'regarding_id'], 'ltr_regarding_idx');
            $table->index(['letter_date'], 'ltr_letter_date_idx');
        });

        $this->create('letter_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('letters', 'id', 'ltr_reg_letter_fk')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'ltr_reg_org_fk')->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('registry_number', 80)->nullable();
            $table->unsignedInteger('sequence_number')->nullable();
            $table->string('period_key', 10)->nullable();
            $table->string('status', 20);
            $table->dateTime('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users', 'id', 'ltr_reg_registered_by_fk')->nullOnDelete();
            $table->date('received_on')->nullable();
            $table->date('due_on')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users', 'id', 'ltr_reg_closed_by_fk')->nullOnDelete();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->unique(['letter_id', 'organization_id'], 'ltr_reg_letter_org_uq');
            $table->unique(['organization_id', 'registry_number'], 'ltr_reg_org_number_uq');
            $table->index(['organization_id', 'direction', 'status'], 'ltr_reg_org_dir_status_idx');
            $table->index(['organization_id', 'due_on'], 'ltr_reg_org_due_idx');
        });

        $this->create('letter_routings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained('letter_registrations', 'id', 'ltr_rt_registration_fk')->cascadeOnDelete();
            $table->foreignUuid('letter_id')->constrained('letters', 'id', 'ltr_rt_letter_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->foreignId('from_user_id')->nullable()->constrained('users', 'id', 'ltr_rt_from_user_fk')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users', 'id', 'ltr_rt_to_user_fk')->nullOnDelete();
            $table->foreignUuid('to_unit_id')->nullable()->constrained('organization_units', 'id', 'ltr_rt_to_unit_fk')->nullOnDelete();
            $table->text('instruction');
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();
            $table->index(['to_user_id', 'status'], 'ltr_rt_to_user_status_idx');
            $table->index(['registration_id', 'status'], 'ltr_rt_registration_status_idx');
        });

        $this->create('letter_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('letters', 'id', 'ltr_att_letter_fk')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users', 'id', 'ltr_att_uploaded_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['letter_id', 'kind'], 'ltr_att_letter_kind_idx');
        });

        $this->create('letter_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('letters', 'id', 'ltr_evt_letter_fk')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained('letter_registrations', 'id', 'ltr_evt_registration_fk')->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'ltr_evt_actor_fk')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->dateTime('created_at');
            $table->index(['letter_id', 'created_at'], 'ltr_evt_letter_created_idx');
        });

        $this->create('letter_numbering_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations', 'id', 'ltr_num_org_fk')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('pattern', 120);
            $table->string('reset_policy', 20)->default('ethiopian_year');
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('direction_code', 12)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'ltr_num_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'direction'], 'ltr_num_org_direction_uq');
        });

        $this->create('letter_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'ltr_seq_org_fk')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('period_key', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'direction', 'period_key'], 'ltr_seq_org_dir_period_uq');
        });
    }

    public function down(): void
    {
        foreach (['letter_sequences', 'letter_numbering_rules', 'letter_events', 'letter_attachments', 'letter_routings', 'letter_registrations', 'letters', 'letter_template_versions', 'letter_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function create(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }
};
