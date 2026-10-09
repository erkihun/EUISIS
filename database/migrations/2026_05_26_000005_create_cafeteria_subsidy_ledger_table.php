<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_subsidy_ledger')) {
            return;
        }

        Schema::create('cafeteria_subsidy_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('cafeteria_transaction_id')->nullable()->constrained('cafeteria_transactions')->nullOnDelete();
            $table->foreignUuid('public_holiday_id')->nullable()->constrained('public_holidays')->nullOnDelete();
            $table->date('ledger_date')->index();
            $table->string('entry_type')->index();
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->boolean('working_day')->default(true);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'ledger_date']);
            $table->index(['employee_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_subsidy_ledger');
    }
};
