<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_application_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vacancy_application_id')->constrained('vacancy_applications')->cascadeOnDelete();
            $table->string('document_type');
            $table->string('original_filename');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->index('vacancy_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_application_documents');
    }
};
