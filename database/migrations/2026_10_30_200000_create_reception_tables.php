<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use Database\Seeders\CodeRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A first MySQL run stopped on an over-long index name after creating
        // reception_queue_tickets without all of its keys. Nothing can have
        // written to it, so rebuild it rather than patch the missing keys.
        if (Schema::hasTable('reception_queue_tickets')
            && ! Schema::hasIndex('reception_queue_tickets', 'reception_queue_tickets_reception_counter_id_status_index')) {
            Schema::drop('reception_queue_tickets');
        }

        $this->createOnce('reception_desks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('location_en')->nullable();
            $table->text('location_am')->nullable();
            $table->json('operating_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'is_active']);
        });

        $this->createOnce('reception_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('status', 20)->default('closed');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['reception_desk_id', 'code']);
            $table->index(['reception_desk_id', 'status']);
        });

        $this->createOnce('reception_desk_staff', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('officer');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['reception_desk_id', 'user_id']);
        });

        $this->createOnce('reception_desk_service', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['reception_desk_id', 'service_id']);
        });

        $this->createOnce('reception_visitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->text('phone')->nullable();
            $table->string('phone_hash', 64)->nullable()->index();
            $table->string('category', 40)->default('service_seeker');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['name', 'created_at']);
        });

        $this->createOnce('reception_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->foreignUuid('visitor_id')->constrained('reception_visitors')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->dateTimeTz('scheduled_at');
            $table->string('status', 30)->default('scheduled');
            $table->dateTimeTz('checked_in_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'scheduled_at', 'status'], 'reception_appointments_org_time_status_index');
            $table->unique(['reception_desk_id', 'scheduled_at'], 'reception_appointments_desk_time_unique');
        });

        $this->createOnce('reception_visits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->foreignUuid('visitor_id')->constrained('reception_visitors')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('reception_appointments')->nullOnDelete();
            $table->foreignId('reception_officer_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 500)->nullable();
            $table->string('status', 30)->default('checked_in');
            $table->string('outcome', 40)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->dateTimeTz('checked_in_at');
            $table->dateTimeTz('service_started_at')->nullable();
            $table->dateTimeTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'checked_in_at', 'status']);
            $table->index(['reception_desk_id', 'checked_in_at']);
        });

        $this->createOnce('reception_queue_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->date('queue_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['reception_desk_id', 'queue_date']);
        });

        $this->createOnce('reception_queue_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('reception_visits')->cascadeOnDelete();
            $table->foreignUuid('reception_desk_id')->constrained('reception_desks')->cascadeOnDelete();
            $table->foreignUuid('reception_counter_id')->nullable()->constrained('reception_counters')->nullOnDelete();
            $table->date('queue_date');
            $table->unsignedInteger('queue_number');
            $table->string('display_number', 30);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('waiting');
            $table->dateTimeTz('issued_at');
            $table->dateTimeTz('called_at')->nullable();
            $table->dateTimeTz('service_started_at')->nullable();
            $table->dateTimeTz('completed_at')->nullable();
            $table->unsignedSmallInteger('recall_count')->default(0);
            $table->timestamps();
            $table->unique(['reception_desk_id', 'queue_date', 'queue_number'], 'reception_queue_daily_number_unique');
            $table->index(['reception_desk_id', 'queue_date', 'status'], 'reception_queue_tickets_desk_date_status_index');
            $table->index(['reception_counter_id', 'status']);
        });

        $this->createOnce('reception_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('reception_visits')->cascadeOnDelete();
            $table->foreignUuid('from_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('to_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('to_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->foreignId('referred_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTimeTz('referred_at');
            $table->dateTimeTz('acknowledged_at')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();
            $table->index(['to_organization_id', 'status', 'referred_at']);
            $table->index(['visit_id', 'referred_at']);
        });

        CodeRuleSeeder::ensureFor(CodeRuleEntityType::ReceptionDesk);
    }

    /**
     * MySQL DDL is not transactional: skip tables a failed earlier run already created.
     */
    private function createOnce(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_referrals');
        Schema::dropIfExists('reception_queue_tickets');
        Schema::dropIfExists('reception_queue_sequences');
        Schema::dropIfExists('reception_visits');
        Schema::dropIfExists('reception_appointments');
        Schema::dropIfExists('reception_visitors');
        Schema::dropIfExists('reception_desk_service');
        Schema::dropIfExists('reception_desk_staff');
        Schema::dropIfExists('reception_counters');
        Schema::dropIfExists('reception_desks');
    }
};
