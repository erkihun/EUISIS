<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cafeteria_transactions')) {
            return;
        }

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cafeteria_transactions', 'transaction_number')) {
                $table->string('transaction_number')->nullable()->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'employee_id')) {
                $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'id_card_id')) {
                $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards')->nullOnDelete();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'cafeteria_provider_id')) {
                $table->foreignUuid('cafeteria_provider_id')->nullable()->constrained('cafeteria_providers')->nullOnDelete();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'transaction_date')) {
                $table->date('transaction_date')->nullable()->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'transaction_time')) {
                $table->time('transaction_time')->nullable();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'scanned_at')) {
                $table->timestamp('scanned_at')->nullable()->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'meal_amount')) {
                $table->decimal('meal_amount', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'subsidy_amount_applied')) {
                $table->decimal('subsidy_amount_applied', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'employee_payable_amount')) {
                $table->decimal('employee_payable_amount', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'deduction_amount')) {
                $table->decimal('deduction_amount', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'transaction_type')) {
                $table->string('transaction_type')->default('scan');
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'status')) {
                $table->string('status')->default('accepted')->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'scan_sequence_for_day')) {
                $table->integer('scan_sequence_for_day')->default(1);
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'is_extra_scan')) {
                $table->boolean('is_extra_scan')->default(false)->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'is_holiday')) {
                $table->boolean('is_holiday')->default(false)->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'is_working_day')) {
                $table->boolean('is_working_day')->default(true)->index();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'qr_reference')) {
                $table->string('qr_reference')->nullable();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'notes')) {
                $table->text('notes')->nullable();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. This migration repairs fresh installs
        // where an older placeholder table already existed.
    }
};
