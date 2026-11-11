<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded file of positions, and the fate of every row inside it.
 *
 * Same shape as the employee import tables: the batch is written when the file
 * is checked, so an upload that was previewed and abandoned still leaves a
 * record, and each row keeps exactly what the file said.
 *
 * `uploaded_by` is an unsigned big integer because `users.id` is a bigint.
 *
 * Each table is created only when it is missing: MySQL does not roll a
 * failed migration back, so a second run must be able to carry on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('position_import_batches')) {
            $this->createBatches();
        }

        if (! Schema::hasTable('position_import_batch_rows')) {
            $this->createRows();
        }
    }

    private function createBatches(): void
    {
        Schema::create('position_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            // Only set when every row of the file targets one organization.
            $table->foreignUuid('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();
            $table->string('file_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->json('error_summary')->nullable();
            $table->timestamps();

            $table->index(['uploaded_by', 'created_at']);
        });
    }

    private function createRows(): void
    {
        Schema::create('position_import_batch_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')
                ->constrained('position_import_batches')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('row_data');
            $table->string('status', 16)->default('valid')->index();
            $table->json('errors')->nullable();
            // Archiving a position must not erase the evidence it was imported.
            $table->foreignUuid('position_id')->nullable()
                ->constrained('positions')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->unique(['batch_id', 'row_number'], 'position_import_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_import_batch_rows');
        Schema::dropIfExists('position_import_batches');
    }
};
