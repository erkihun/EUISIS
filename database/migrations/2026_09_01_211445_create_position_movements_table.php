<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('from_organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignUuid('to_organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('moved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('moved_at')->index();
            $table->timestamps();

            $table->index(['position_id', 'moved_at']);
            $table->index(['organization_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_movements');
    }
};
