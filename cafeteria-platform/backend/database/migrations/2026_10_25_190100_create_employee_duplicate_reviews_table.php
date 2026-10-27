<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions about possible duplicate employees: which record matched which,
 * how strongly and why, what the reviewer decided (a different person, or a
 * duplicate to resolve) and the written reason. Records are never merged
 * automatically; these rows are the review trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_duplicate_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('matched_employee_id');
            $table->string('match_level', 32);
            $table->json('reasons')->nullable();
            $table->string('decision', 32);
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'matched_employee_id'], 'employee_duplicate_reviews_pair_unique');
            $table->index(['decision'], 'employee_duplicate_reviews_decision_idx');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('matched_employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_duplicate_reviews');
    }
};
