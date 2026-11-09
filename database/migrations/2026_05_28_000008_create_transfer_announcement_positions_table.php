<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_announcement_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('transfer_announcement_id')
                ->constrained('transfer_announcements')
                ->cascadeOnDelete();

            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('position_id')->constrained('positions');

            $table->string('grade_level', 50)->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();

            // Number of vacant slots for this position row
            $table->unsignedSmallInteger('vacancy_count')->default(1);

            $table->timestamps();

            $table->index(['transfer_announcement_id', 'organization_id'], 'tap_ann_org_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_announcement_positions');
    }
};
