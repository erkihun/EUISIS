<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One CSV upload of employees, and the fate of every row inside it.
 *
 * The batch is written at validation time rather than at import, so a file that
 * is uploaded, previewed and then abandoned still leaves a record of who tried
 * to load what. That matters for an operation that can create hundreds of
 * employees at once.
 *
 * `uploaded_by` is an unsigned big integer because `users.id` is a bigint in
 * this project, unlike almost every other key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('uploaded_by')->nullable();

            /*
             * The organization the importer was working within. Nullable
             * because an unrestricted admin may load a file spanning several
             * organizations, in which case the per-row organization is the
             * authoritative one.
             */
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

    public function down(): void
    {
        Schema::dropIfExists('employee_import_batches');
    }
};
