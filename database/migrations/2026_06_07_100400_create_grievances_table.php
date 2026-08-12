<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number')->unique();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('origin_level'); // woreda, pool, organization, organization_unit
            $table->foreignUuid('category_id')->constrained('grievance_categories')->restrictOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->string('status')->default('draft');
            $table->nullableUuidMorphs('current_assigned'); // committee, tribunal, etc.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('requirement_checked_at')->nullable();
            $table->boolean('requirement_fulfilled')->nullable();
            $table->text('requirement_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index('origin_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievances');
    }
};
