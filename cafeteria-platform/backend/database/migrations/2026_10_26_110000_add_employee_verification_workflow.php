<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The verified-employee stage of the Golden Path (ADR 0008): a registered
 * employee is a draft, is submitted, verified against a checklist and approved
 * by someone else before it becomes active; its first placement stays pending
 * until then. These columns hold the current state of that workflow (who and
 * when for each step, notes, the checklist); every step is also an audit row.
 *
 * Existing employees were created before the workflow existed. They keep
 * their status (active ones stay active, with their placements and cards) and
 * are marked legacy_approved, so nothing in service is broken and the history
 * says how they were admitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('verification_status', 24)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->json('verification_checklist')->nullable();
            // The verification and approval queues list by state, oldest submission first.
            $table->index(['verification_status', 'submitted_at'], 'employees_verification_queue_idx');
        });

        $this->backfill();
    }

    /** Employees created before the workflow keep their status and are marked legacy_approved. */
    public function backfill(): void
    {
        DB::table('employees')->whereNull('verification_status')->update(['verification_status' => 'legacy_approved']);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_verification_queue_idx');
            foreach (['registered_by', 'submitted_by', 'verified_by', 'approved_by'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn(['verification_status', 'registered_by', 'submitted_by', 'submitted_at', 'verified_by', 'verified_at',
                'approved_by', 'approved_at', 'verification_notes', 'verification_checklist']);
        });
    }
};
