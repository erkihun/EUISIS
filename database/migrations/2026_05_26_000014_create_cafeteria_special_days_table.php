<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_special_days')) {
            return;
        }

        Schema::create('cafeteria_special_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('special_date')->index();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('day_type'); // open_day|closed_day|subsidy_day|no_subsidy_day|provider_only|emergency_closure
            $table->boolean('is_open');
            $table->boolean('is_subsidy_day');
            $table->uuid('cafeteria_provider_id')->nullable();
            $table->foreign('cafeteria_provider_id')->references('id')->on('cafeteria_providers')->nullOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->text('reason_en')->nullable();
            $table->text('reason_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['special_date', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_special_days');
    }
};
