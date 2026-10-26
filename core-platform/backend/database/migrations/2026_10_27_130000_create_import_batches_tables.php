<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business imports (ADR 0009): an uploaded file becomes a batch whose rows are
 * validated with the Core's own rules, previewed, approved by a second
 * person and processed by a queued job through the domain actions.
 *
 *  - A row's data is stored encrypted (it may carry a national ID); its status
 *    and errors (field, code, message) are plain, for the error report.
 *  - A row is processed exactly once: it is marked processed, with the record
 *    it produced, in the same transaction as the change, and (batch, row
 *    number) is unique, so a retried job never repeats a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 24);
            $table->string('status', 24)->default('uploaded');
            $table->string('source_filename', 255);
            $table->string('storage_disk', 40);
            $table->string('storage_path', 255);
            $table->char('checksum', 64);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->uuid('scope_organization_id')->nullable();
            $table->uuid('hierarchy_version_id')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('error_summary')->nullable();
            $table->timestamps();
            $table->unique(['storage_disk', 'storage_path'], 'import_batches_storage_unique');
            $table->index(['status', 'created_at'], 'import_batches_status_idx');
            $table->index(['checksum'], 'import_batches_checksum_idx');
            $table->index(['scope_organization_id'], 'import_batches_scope_idx');
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_batch_id');
            $table->unsignedInteger('row_number');
            $table->text('payload');
            $table->string('status', 16);
            $table->json('errors')->nullable();
            $table->string('result_type', 40)->nullable();
            $table->string('result_id', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number'], 'import_rows_batch_row_unique');
            $table->index(['import_batch_id', 'status'], 'import_rows_batch_status_idx');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
