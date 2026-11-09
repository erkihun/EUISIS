<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_tribunal_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->unique()->constrained('grievances')->cascadeOnDelete();
            $table->string('case_number')->unique();
            $table->string('status')->default('open'); // open, hearing, decided, closed
            $table->text('decision_summary')->nullable();
            $table->date('hearing_date')->nullable();
            $table->date('decision_date')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrative_tribunal_cases');
    }
};
