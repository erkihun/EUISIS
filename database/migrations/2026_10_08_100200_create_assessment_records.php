<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assessment_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('form_version_id')->constrained('assessment_form_versions')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('employee_snapshot');
            $table->string('status', 20)->default('assigned');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->decimal('percentage', 12, 4)->nullable();
            $table->decimal('contribution', 12, 4)->nullable();
            $table->string('unassessed_reason', 20)->nullable();
            $table->text('unassessed_note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'assessment_type_id', 'period_start', 'period_end'], 'assessment_record_period_unique');
        });
        Schema::create('assessment_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->json('answers')->nullable();
            $table->json('score_snapshot')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['assessment_record_id', 'evaluator_id'], 'assessment_response_evaluator_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_responses');
        Schema::dropIfExists('assessment_records');
    }
};
