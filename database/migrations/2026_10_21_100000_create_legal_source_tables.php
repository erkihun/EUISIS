<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal Source master (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-001): the proclamations, regulations, directives, decisions,
 * approved structures and studies that design records cite as evidence.
 *
 *   legal_sources            — one row per instrument; organization_id null
 *                              means a city-level source every institution
 *                              may cite
 *   legal_source_attachments — the scanned document, on the private disk;
 *                              never a raw path to the browser
 *   legal_source_citations   — polymorphic: which design record cites which
 *                              source (mandates, standards, hierarchy
 *                              versions, baselines, …)
 *
 * Mandates gain a nullable legal_source_id; their inline instrument fields
 * stay for compatibility. Additive and resumable; nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('legal_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->foreignUuid('source_type_id')->constrained('legal_instrument_types')->restrictOnDelete();
            $table->string('issuing_authority')->nullable();
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'legal_sources_org_code_unique');
            $table->index(['status', 'effective_from'], 'legal_sources_status_from_idx');
            $table->index('source_type_id', 'legal_sources_type_idx');
        });

        SchemaResume::create('legal_source_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('legal_source_id')->constrained('legal_sources')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('label')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('legal_source_id', 'legal_source_attachments_source_idx');
        });

        SchemaResume::create('legal_source_citations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('legal_source_id')->constrained('legal_sources')->cascadeOnDelete();
            $table->string('subject_type', 120);
            $table->uuid('subject_id');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['legal_source_id', 'subject_type', 'subject_id'], 'legal_source_citations_unique');
            $table->index(['subject_type', 'subject_id'], 'legal_source_citations_subject_idx');
        });

        if (! Schema::hasColumn('institution_mandates', 'legal_source_id')) {
            Schema::table('institution_mandates', function (Blueprint $table): void {
                $table->uuid('legal_source_id')->nullable()->after('issuing_authority');
                $table->index('legal_source_id', 'institution_mandates_legal_source_idx');
            });
            // SQLite rebuilds the table for a foreign key and drops partial
            // unique indexes on the way; the constraint is for real servers.
            if (DB::getDriverName() !== 'sqlite') {
                Schema::table('institution_mandates', function (Blueprint $table): void {
                    $table->foreign('legal_source_id')->references('id')->on('legal_sources')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('institution_mandates', 'legal_source_id')) {
            Schema::table('institution_mandates', function (Blueprint $table): void {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['legal_source_id']);
                }
                $table->dropIndex('institution_mandates_legal_source_idx');
                $table->dropColumn('legal_source_id');
            });
        }
        Schema::dropIfExists('legal_source_citations');
        Schema::dropIfExists('legal_source_attachments');
        Schema::dropIfExists('legal_sources');
    }
};
