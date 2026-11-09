<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_occupancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_establishment_id')->constrained('position_establishments');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('employee_assignment_id')->constrained('employee_assignments');
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('position_id')->constrained('positions');
            $table->date('occupied_from');
            $table->date('occupied_until')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->index(['position_establishment_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['position_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_occupancies');
    }
};
