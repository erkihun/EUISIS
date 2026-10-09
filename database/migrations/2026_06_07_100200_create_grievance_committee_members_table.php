<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_committee_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('committee_id')->constrained('grievance_committees')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role'); // chairperson, secretary, member
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active'); // active, inactive
            $table->timestamps();

            $table->unique(['committee_id', 'employee_id', 'effective_from'], 'unique_member_per_committee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_committee_members');
    }
};
