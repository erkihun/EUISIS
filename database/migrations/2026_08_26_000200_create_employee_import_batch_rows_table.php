<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row of an uploaded CSV, with its validation outcome.
 *
 * The raw row is stored as JSON exactly as it was read. When an import is
 * disputed weeks later, the question is always "what did the file actually
 * say?", and re-deriving that from the created employee is impossible once
 * records have been edited.
 *
 * `employee_id` is deliberately nullOnDelete rather than cascade: deleting an
 * employee must not erase the evidence that they were imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_batch_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')
                ->constrained('employee_import_batches')
                ->cascadeOnDelete();

            $table->unsignedInteger('row_number');
            $table->json('row_data');
            $table->string('status', 16)->default('valid')->index();
            $table->json('errors')->nullable();

            $table->foreignUuid('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->unique(['batch_id', 'row_number'], 'employee_import_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_batch_rows');
    }
};
