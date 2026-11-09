<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public feedback QR token for an employee.
 *
 * Deliberately separate from the ID card QR (`id_cards.public_card_uuid`):
 * that token opens the OTP-gated identity checker, this one opens an anonymous
 * feedback form. Keeping them apart means revoking a leaked feedback QR never
 * disturbs a printed ID card, and a feedback URL can never be replayed against
 * the ID checker.
 *
 * The token is a long random string rather than the employee UUID, so the URL
 * carries no identifier that appears anywhere else in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_feedback_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 16)->default('active')->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamps();

            /*
             * One active token per employee is enforced in the service layer
             * rather than by a unique index: revoked and suspended rows are
             * retained for audit, so several rows per employee are expected.
             */
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_feedback_tokens');
    }
};
