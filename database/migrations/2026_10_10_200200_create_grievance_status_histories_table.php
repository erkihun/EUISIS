<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable, append-only record of grievance status transitions
 * (spec §66). Deliberately has no updated_at: rows are written once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('transitioned_at');

            $table->index(['grievance_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_status_histories');
    }
};
