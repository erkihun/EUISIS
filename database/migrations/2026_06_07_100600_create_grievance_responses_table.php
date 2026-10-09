<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->foreignUuid('drafted_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('compiled_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_body_en');
            $table->text('response_body_am')->nullable();
            $table->string('status')->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->unsignedTinyInteger('revision_round')->default(1);
            $table->timestamp('drafted_at')->nullable();
            $table->timestamp('compiled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['grievance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_responses');
    }
};
