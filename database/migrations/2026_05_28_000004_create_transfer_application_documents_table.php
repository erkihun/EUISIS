<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_application_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('transfer_application_id')->constrained('transfer_applications')->cascadeOnDelete();
            $table->string('document_type', 80);
            $table->string('original_name');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 50);
            $table->unsignedInteger('file_size');

            $table->string('verification_status', 20)->default('pending');
            $table->text('verification_remark')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['transfer_application_id', 'verification_status'], 'tad_app_id_ver_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_application_documents');
    }
};
