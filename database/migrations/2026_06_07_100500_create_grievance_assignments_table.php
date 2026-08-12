<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignment_type')->default('committee'); // committee, tribunal
            $table->text('notes')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('due_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['grievance_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_assignments');
    }
};
