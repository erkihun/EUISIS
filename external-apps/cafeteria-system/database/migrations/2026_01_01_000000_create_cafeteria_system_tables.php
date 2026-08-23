<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cafeteria System schema — local database only.
 *
 * Note what is absent: there is NO foreign key to employees, id_cards,
 * organizations or users. Those live in EUISIS and are referenced only by
 * denormalized business identifiers (employee_number) captured at serve time.
 * That is what makes this application independently deployable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('branch_name')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->string('contact_person')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('settlement_account')->nullable();
            $table->timestamps();
        });

        Schema::create('cafeteria_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()
                ->constrained('cafeteria_providers')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('operator');
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('cafeteria_service_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transaction_number')->unique();
            $table->foreignUuid('provider_id')->constrained('cafeteria_providers')->cascadeOnDelete();

            // Verified snapshot from EUISIS. Business identifiers only — no
            // national id, phone, address, salary or document references.
            $table->string('employee_number')->index();
            $table->string('employee_name')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('card_status', 32)->nullable();
            $table->string('eligibility_result', 32)->nullable();

            $table->string('status', 16)->default('served')->index();
            $table->decimal('meal_amount', 12, 2)->default(0);
            $table->decimal('subsidy_amount', 12, 2)->default(0);
            $table->decimal('employee_payable', 12, 2)->default(0);
            $table->timestamp('served_at')->index();
            $table->foreignUuid('served_by_user_id')->nullable()
                ->constrained('cafeteria_users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Backs the "one meal per employee per provider per day" rule.
            // Explicit short name: the generated one exceeds MySQL's 64-char
            // identifier limit.
            $table->index(['employee_number', 'provider_id', 'served_at'], 'cst_emp_provider_served_idx');
        });

        Schema::create('cafeteria_api_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->boolean('success')->default(false);
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('requested_at')->index();
            $table->timestamps();
        });

        Schema::create('cafeteria_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('cafeteria_providers')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('total_subsidy', 14, 2)->default(0);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cafeteria_user_id')->nullable()
                ->constrained('cafeteria_users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cafeteria_settlements');
        Schema::dropIfExists('cafeteria_api_logs');
        Schema::dropIfExists('cafeteria_service_transactions');
        Schema::dropIfExists('cafeteria_users');
        Schema::dropIfExists('cafeteria_providers');
    }
};
