<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_day_rules')) {
            return;
        }

        Schema::create('cafeteria_day_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedTinyInteger('day_of_week'); // 1=Mon … 7=Sun
            $table->boolean('is_open')->default(true);
            $table->boolean('is_subsidy_day')->default(true);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->string('notes')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('day_of_week');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_day_rules');
    }
};
