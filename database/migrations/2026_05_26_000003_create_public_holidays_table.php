<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_holidays')) {
            return;
        }

        Schema::create('public_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->date('holiday_date')->index();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_type')->nullable();
            $table->string('country_code', 10)->default('ET')->nullable();
            $table->string('region')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['holiday_date', 'is_active']);
            $table->index(['is_recurring', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
