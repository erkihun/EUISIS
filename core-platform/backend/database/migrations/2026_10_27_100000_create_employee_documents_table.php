<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee identity evidence (ADR 0009): documents, the photo and the
 * signature, one row per stored file version.
 *
 *  - `kind` is document, photo or signature; a document also has a type
 *    (appointment letter, identity document, …).
 *  - The versions of one document share a `lineage_id` (the first version's
 *    id). A replacement adds version n+1 and keeps the earlier ones; exactly
 *    one version of a lineage is current, and an employee has at most one
 *    current photo and one current signature (partial unique indexes; a
 *    generated column on MySQL/MariaDB, which have none).
 *  - Files live on a private disk under a generated name; the original file
 *    name is metadata only. The SHA-256 checksum supports integrity checks.
 *  - A verified row names its verifier (a CHECK constraint where the database
 *    supports adding one).
 *
 * Nothing is backfilled: the photo and signature paths imported from EUISIS
 * point at files the Core does not hold, so they are not treated as evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partial = in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true);

        Schema::create('employee_documents', function (Blueprint $table) use ($partial): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->string('kind', 16);
            $table->string('document_type', 40);
            $table->string('title', 200)->nullable();
            $table->uuid('lineage_id');
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->string('status', 16)->default('pending');
            $table->string('storage_disk', 40);
            $table->string('storage_path', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamps();
            if (! $partial) {
                $table->uuid('current_lineage_id')->nullable()->storedAs('case when is_current then lineage_id end');
                $table->string('current_identity_slot', 60)->nullable()
                    ->storedAs("case when is_current and kind in ('photo', 'signature') then concat(employee_id, ':', kind) end");
                $table->unique(['current_lineage_id'], 'employee_documents_one_current_per_lineage');
                $table->unique(['current_identity_slot'], 'employee_documents_one_current_identity');
            }
            $table->unique(['lineage_id', 'version'], 'employee_documents_lineage_version_unique');
            $table->unique(['storage_disk', 'storage_path'], 'employee_documents_storage_unique');
            $table->index(['employee_id', 'kind', 'is_current'], 'employee_documents_employee_kind_idx');
            $table->index(['status'], 'employee_documents_status_idx');
            $table->index(['checksum'], 'employee_documents_checksum_idx');
            $table->index(['expires_at'], 'employee_documents_expires_idx');
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });

        if ($partial) {
            $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';
            DB::statement("CREATE UNIQUE INDEX employee_documents_one_current_per_lineage ON employee_documents (lineage_id) WHERE is_current = {$true}");
            DB::statement("CREATE UNIQUE INDEX employee_documents_one_current_identity ON employee_documents (employee_id, kind) WHERE is_current = {$true} AND kind IN ('photo', 'signature')");
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_verified_has_verifier CHECK (status <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL))");
            DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_version_positive CHECK (version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
