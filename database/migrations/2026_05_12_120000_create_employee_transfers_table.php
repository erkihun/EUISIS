<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('from_organization_id')->constrained('organizations');
            $table->foreignUuid('to_organization_id')->constrained('organizations');
            $table->foreignUuid('from_position_id')->nullable()->constrained('positions');
            $table->foreignUuid('to_position_id')->nullable()->constrained('positions');
            $table->foreignUuid('current_assignment_id')->constrained('employee_assignments');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('current_org_confirmed_by')->nullable()->constrained('users');
            $table->foreignId('receiving_organization_confirmed_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->text('transfer_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('effective_date')->index();
            $table->string('status')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('current_org_confirmed_at')->nullable();
            $table->timestamp('receiving_org_confirmed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['from_organization_id', 'status']);
            $table->index(['to_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_transfers');
    }
};
