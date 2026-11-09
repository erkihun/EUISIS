<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_transactions')) {
            return;
        }

        Schema::create('cafeteria_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transaction_number')->unique();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('id_card_id')->nullable()->constrained('id_cards')->nullOnDelete();
            $table->foreignUuid('cafeteria_provider_id')->constrained('cafeteria_providers');
            $table->date('transaction_date')->index();
            $table->time('transaction_time')->nullable();
            $table->dateTime('scanned_at')->index();
            $table->decimal('meal_amount', 12, 2)->nullable();
            $table->decimal('subsidy_amount_applied', 12, 2)->default(0);
            $table->decimal('employee_payable_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->string('transaction_type')->default('scan');
            $table->string('status')->default('accepted')->index();
            $table->integer('scan_sequence_for_day')->default(1);
            $table->boolean('is_extra_scan')->default(false)->index();
            $table->boolean('is_holiday')->default(false)->index();
            $table->boolean('is_working_day')->default(true)->index();
            $table->string('qr_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'transaction_date']);
            $table->index(['cafeteria_provider_id', 'transaction_date'], 'ct_provider_date_idx');
            $table->index(['employee_id', 'transaction_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_transactions');
    }
};
