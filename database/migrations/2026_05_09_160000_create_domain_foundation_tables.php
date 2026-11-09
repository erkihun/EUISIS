<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_type_id')->constrained('organization_types');
            $table->uuid('merged_into_id')->nullable();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('legal_basis_ref')->nullable();
            $table->string('status')->index();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('is_demo')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreign('merged_into_id')
                ->references('id')
                ->on('organizations');
        });

        Schema::create('hierarchy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version_name')->unique();
            $table->string('source_document')->nullable();
            $table->string('status')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approval_date')->nullable();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('organization_edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('hierarchy_version_id')->constrained('hierarchy_versions');
            $table->foreignUuid('parent_organization_id')->constrained('organizations');
            $table->foreignUuid('child_organization_id')->constrained('organizations');
            $table->string('relationship_type')->index();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->timestamps();
            $table->unique(['hierarchy_version_id', 'parent_organization_id', 'child_organization_id', 'relationship_type'], 'organization_edges_unique');
        });

        Schema::create('organization_name_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('organization_closure_paths', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('hierarchy_version_id')->constrained('hierarchy_versions');
            $table->foreignUuid('ancestor_organization_id')->constrained('organizations');
            $table->foreignUuid('descendant_organization_id')->constrained('organizations');
            $table->unsignedInteger('depth');
            $table->unique(['hierarchy_version_id', 'ancestor_organization_id', 'descendant_organization_id'], 'organization_closure_unique');
        });

        Schema::create('organization_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status')->index();
            $table->string('request_type');
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->string('code')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('employee_number')->unique();
            $table->uuid('current_assignment_id')->nullable()->index();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('full_name')->index();
            $table->date('date_of_birth')->nullable()->index();
            $table->string('gender', 32)->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('status')->index();
            $table->decimal('data_quality_score', 5, 2)->default(100);
            $table->json('metadata')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('position_id')->nullable()->constrained('positions');
            $table->foreignUuid('hierarchy_version_id')->nullable()->constrained('hierarchy_versions');
            $table->string('assignment_status')->index();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('is_current')->default(false)->index();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('employment_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('status')->index();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('document_type');
            $table->string('file_path');
            $table->string('storage_disk')->default('private');
            $table->boolean('is_private')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_duplicate_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->uuid('matched_employee_id')->nullable()->index();
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->json('matched_fields')->nullable();
            $table->string('status')->default('flagged')->index();
            $table->timestamps();
        });

        Schema::create('user_organization_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->uuid('service_provider_id')->nullable()->index();
            $table->uuid('service_type_id')->nullable()->index();
            $table->string('scope_type')->index();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('api_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_provider_id')->nullable()->index();
            $table->string('name');
            $table->string('token_hash')->unique();
            $table->json('abilities')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_provider_id')->nullable()->index();
            $table->uuid('api_client_id')->nullable()->index();
            $table->string('device_identifier')->unique();
            $table->string('device_name')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->uuid('api_client_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->string('request_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('card_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('status')->index();
            $table->text('request_reason')->nullable();
            $table->text('verification_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('card_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('version');
            $table->json('template_schema')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('id_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('card_request_id')->nullable()->constrained('card_requests');
            $table->uuid('previous_card_id')->nullable()->index();
            $table->foreignUuid('card_template_id')->nullable()->constrained('card_templates');
            $table->string('card_number')->unique();
            $table->string('status')->index();
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('token_last_rotated_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('display_snapshot')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('card_print_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_number')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('status')->index();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('card_print_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('card_print_batch_id')->constrained('card_print_batches');
            $table->foreignUuid('id_card_id')->constrained('id_cards');
            $table->timestamps();
            $table->unique(['card_print_batch_id', 'id_card_id']);
        });

        Schema::create('card_issuances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_card_id')->constrained('id_cards');
            $table->foreignId('issued_by')->constrained('users');
            $table->dateTime('issued_at');
            $table->string('recipient_name')->nullable();
            $table->timestamps();
        });

        Schema::create('card_replacements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('old_card_id')->constrained('id_cards');
            $table->foreignUuid('new_card_id')->constrained('id_cards');
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('card_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards');
            $table->uuid('service_type_id')->nullable()->index();
            $table->uuid('service_provider_id')->nullable()->index();
            $table->uuid('api_client_id')->nullable()->index();
            $table->uuid('device_binding_id')->nullable()->index();
            $table->string('result_code')->index();
            $table->boolean('allowed')->index();
            $table->string('request_ip', 45)->nullable();
            $table->text('request_user_agent')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('service_type_id')->constrained('service_types');
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('active')->index();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('service_provider_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
            $table->unique(['service_provider_id', 'user_id']);
        });

        Schema::create('entitlement_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_type_id')->constrained('service_types');
            $table->string('name');
            $table->json('rule_definition')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('service_type_id')->constrained('service_types');
            $table->foreignUuid('service_provider_id')->nullable()->constrained('service_providers');
            $table->foreignUuid('entitlement_rule_id')->nullable()->constrained('entitlement_rules');
            $table->string('status')->index();
            $table->unsignedInteger('quota_limit')->nullable();
            $table->unsignedInteger('quota_used')->default(0);
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->json('rule_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('service_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees');
            $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards');
            $table->foreignUuid('service_type_id')->constrained('service_types');
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->foreignUuid('entitlement_id')->nullable()->constrained('entitlements');
            $table->string('status')->index();
            $table->dateTime('occurred_at')->index();
            $table->string('reference')->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->string('period')->index();
            $table->decimal('settlement_amount', 12, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('settlement_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('period')->index();
            $table->foreignId('generated_by')->constrained('users');
            $table->dateTime('generated_at');
            $table->timestamps();
        });

        Schema::create('transport_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('transport_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_plan_id')->constrained('transport_plans');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('transport_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_transaction_id')->constrained('service_transactions');
            $table->uuid('transport_route_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('transport_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('provider_settlement_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('meal_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('coupon_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->foreignUuid('meal_plan_id')->nullable()->constrained('meal_plans');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('coupon_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('coupon_program_id')->constrained('coupon_programs');
            $table->unsignedInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('cafeteria_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_transaction_id')->constrained('service_transactions');
            $table->timestamps();
        });

        Schema::create('consumer_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('service_provider_id')->constrained('service_providers');
            $table->timestamps();
        });

        Schema::create('consumer_limits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('consumer_membership_id')->constrained('consumer_memberships');
            $table->unsignedInteger('limit_value')->default(0);
            $table->timestamps();
        });

        Schema::create('consumer_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_transaction_id')->constrained('service_transactions');
            $table->timestamps();
        });

        Schema::create('approval_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_workflow_id')->constrained('approval_workflows');
            $table->unsignedInteger('step_order');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_workflow_id')->constrained('approval_workflows');
            $table->uuidMorphs('approvable');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->string('status')->index();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('task_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_id')->constrained('approvals');
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('actor_type')->nullable();
            $table->string('event_type')->index();
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable()->index();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->string('request_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('notifications_foundation', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('channel');
            $table->string('status')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('opened_by')->constrained('users');
            $table->string('subject');
            $table->string('status')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = [
            'support_tickets',
            'notifications_foundation',
            'audit_logs',
            'task_assignments',
            'approvals',
            'approval_steps',
            'approval_workflows',
            'consumer_transactions',
            'consumer_limits',
            'consumer_memberships',
            'cafeteria_transactions',
            'coupon_balances',
            'coupon_programs',
            'meal_plans',
            'transport_settlements',
            'transport_usages',
            'transport_routes',
            'transport_plans',
            'settlement_runs',
            'provider_settlements',
            'service_transactions',
            'entitlements',
            'entitlement_rules',
            'service_provider_users',
            'service_providers',
            'service_types',
            'card_verifications',
            'card_replacements',
            'card_issuances',
            'card_print_batch_items',
            'card_print_batches',
            'id_cards',
            'card_templates',
            'card_requests',
            'security_events',
            'device_bindings',
            'api_clients',
            'user_organization_scopes',
            'employee_duplicate_flags',
            'employee_documents',
            'employment_status_histories',
            'employee_assignments',
            'employees',
            'positions',
            'organization_change_requests',
            'organization_closure_paths',
            'organization_name_histories',
            'organization_edges',
            'hierarchy_versions',
            'organizations',
            'organization_types',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
