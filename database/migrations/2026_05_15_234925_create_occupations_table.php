<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en', 255)->nullable();
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('skill_level', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category']);
            $table->index(['sort_order', 'name_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupations');
    }
};
