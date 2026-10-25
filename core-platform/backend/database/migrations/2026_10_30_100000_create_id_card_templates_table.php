<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EUISIS ID card templates (the designer's table, all its columns as
 * EUISIS has them after its migrations), so the EUISIS template page runs on
 * the Core. Additive: the Core's own card_templates tables stay for the
 * print record (ADR 0010); CardTemplateMirror keeps them in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_card_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->text('description')->nullable();
            $table->string('front_background_path')->nullable();
            $table->string('back_background_path')->nullable();
            $table->string('seal_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('logo_primary_path')->nullable();
            $table->string('logo_secondary_path')->nullable();
            $table->decimal('width_mm', 6, 2)->nullable();
            $table->decimal('height_mm', 6, 2)->nullable();
            $table->string('orientation', 10)->default('landscape');
            $table->boolean('is_default')->default(false)->index();
            $table->string('status', 10)->default('active');
            $table->json('text_style_config')->nullable();
            $table->json('layout_config')->nullable();
            $table->json('header_config')->nullable();
            $table->json('back_photo_config')->nullable();
            $table->json('employee_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_templates');
    }
};
