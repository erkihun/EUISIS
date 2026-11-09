<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_establishments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('establishment_number')->unique();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units');
            $table->foreignUuid('position_id')->constrained('positions');
            $table->foreignUuid('occupation_id')->nullable()->constrained('occupations');
            $table->unsignedInteger('approved_slots');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('approval_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['position_id', 'status']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_establishments');
    }
};
