<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_cafeteria_exclusions')) {
            return;
        }

        Schema::create('employee_cafeteria_exclusions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->string('exclusion_type'); // leave|sick_leave|maternity_leave|suspension|training|field_assignment|unpaid_leave|other
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('return_to_work_on')->nullable();
            $table->boolean('is_open_ended')->default(false);
            $table->text('reason_en')->nullable();
            $table->text('reason_am')->nullable();
            $table->string('status')->default('active'); // active|ended|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_cafeteria_exclusions');
    }
};
