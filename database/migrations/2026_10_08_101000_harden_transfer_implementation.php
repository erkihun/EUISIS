<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_applications', function (Blueprint $table): void {
            // Approval and implementation are deliberately separate events.  Existing
            // applications stay valid and are treated as effective today when no date
            // was captured by the legacy workflow.
            $table->date('effective_date')->nullable()->index()->after('status');
            $table->timestamp('approved_at')->nullable()->after('selected_at');
            $table->foreignUuid('implementation_assignment_id')
                ->nullable()
                ->after('current_assignment_id')
                ->constrained('employee_assignments')
                ->nullOnDelete();
            $table->foreignId('implemented_by')->nullable()->after('selected_by')->constrained('users')->nullOnDelete();
            $table->timestamp('implemented_at')->nullable()->after('approved_at');
            $table->timestamp('implementation_failed_at')->nullable()->after('implemented_at');
            $table->string('implementation_failure', 1000)->nullable()->after('implementation_failed_at');
            $table->unique('implementation_assignment_id', 'transfer_applications_implementation_assignment_unique');
        });

        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->foreignUuid('transfer_application_id')
                ->nullable()
                ->after('vacancy_announcement_id')
                ->constrained('transfer_applications')
                ->nullOnDelete();
            $table->foreignUuid('destination_assignment_id')
                ->nullable()
                ->after('current_assignment_id')
                ->constrained('employee_assignments')
                ->nullOnDelete();
            $table->json('source_assignment_snapshot')->nullable()->after('metadata');
            $table->json('destination_assignment_snapshot')->nullable()->after('source_assignment_snapshot');
            $table->unique('transfer_application_id', 'employee_transfers_transfer_application_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->dropUnique('employee_transfers_transfer_application_unique');
            $table->dropConstrainedForeignUuid('transfer_application_id');
            $table->dropConstrainedForeignUuid('destination_assignment_id');
            $table->dropColumn(['source_assignment_snapshot', 'destination_assignment_snapshot']);
        });

        Schema::table('transfer_applications', function (Blueprint $table): void {
            $table->dropUnique('transfer_applications_implementation_assignment_unique');
            $table->dropConstrainedForeignUuid('implementation_assignment_id');
            $table->dropConstrainedForeignId('implemented_by');
            $table->dropIndex(['effective_date']);
            $table->dropColumn([
                'effective_date', 'approved_at', 'implemented_at',
                'implementation_failed_at', 'implementation_failure',
            ]);
        });
    }
};
