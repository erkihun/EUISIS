<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staged imports for the design catalogs (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-004): an uploaded file is parsed into rows, validated and matched
 * against the records the organization already has, and only an explicit
 * commit creates records — through the ordinary domain services.
 *
 *   design_import_batches — one upload: file, kind of record, counts, status
 *   design_import_rows    — one staged row: data, errors, what it matched,
 *                           what it created
 *   legacy_code_mappings  — legacy code → canonical record, kept so a re-run
 *                           of the same file never creates a second record
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('design_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number', 30)->unique();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('entity_type', 40);
            $table->foreignUuid('baseline_id')->nullable()->constrained('legacy_baselines')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_disk', 40);
            $table->string('file_path', 500);
            $table->string('fingerprint', 64);
            $table->string('status', 30)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('mapping_required_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->json('error_summary')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'design_import_batches_org_status_idx');
            $table->index(['organization_id', 'entity_type', 'fingerprint'], 'design_import_batches_fingerprint_idx');
        });

        SchemaResume::create('design_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('design_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('status', 30)->default('pending');
            $table->json('errors')->nullable();
            $table->json('resolved')->nullable();
            $table->string('legacy_code', 120)->nullable();
            $table->string('matched_type', 120)->nullable();
            $table->uuid('matched_id')->nullable();
            $table->string('match_source', 20)->nullable();
            $table->string('created_type', 120)->nullable();
            $table->uuid('created_id')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['batch_id', 'row_number'], 'design_import_rows_batch_row_unique');
            $table->index(['batch_id', 'status'], 'design_import_rows_batch_status_idx');
        });

        SchemaResume::create('legacy_code_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('entity_type', 40);
            $table->string('legacy_code', 120);
            $table->string('canonical_type', 120);
            $table->uuid('canonical_id');
            $table->string('source', 20)->default('import');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'entity_type', 'legacy_code'], 'legacy_code_mappings_unique');
            $table->index(['canonical_type', 'canonical_id'], 'legacy_code_mappings_canonical_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_code_mappings');
        Schema::dropIfExists('design_import_rows');
        Schema::dropIfExists('design_import_batches');
    }
};
