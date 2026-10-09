<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isic_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('isic_code', 10)->unique();
            $table->string('section_code', 1)->nullable();
            $table->string('division_code', 2)->nullable();
            $table->string('group_code', 3)->nullable();
            $table->string('class_code', 4)->nullable();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('level', 20);
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['level', 'is_active']);
            $table->index(['section_code', 'division_code']);
        });

        // Self-referencing FK added after the table (and its primary key) exists.
        // On Postgres the PK is created via a separate ALTER, so an in-block
        // self-FK would reference a not-yet-unique column and fail.
        Schema::table('isic_activities', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('isic_activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isic_activities');
    }
};
