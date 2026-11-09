<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Intelligence — Knowledge Assistant storage (docs/intelligence-knowledge.md).
 *
 * knowledge_documents  approved, classified, scoped sources (the body stays here, never in an audit row)
 * knowledge_chunks     retrieval units of a document; PostgreSQL adds a generated tsvector + GIN index
 * knowledge_questions  one row per question asked, redacted, with the answer's mode and citations
 *
 * Resumable (SchemaResume), MySQL-safe: dateTime for moments, named indexes,
 * user foreign keys as foreignId. The full-text column is PostgreSQL-only and
 * guarded; the other drivers retrieve with LIKE.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('intelligence_knowledge_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 190);
            $table->string('slug', 190)->unique('intel_knowledge_docs_slug_unique');
            $table->string('source_type', 20);
            $table->string('source_path', 500)->nullable();
            $table->string('classification', 15);
            // Null: city-wide; otherwise readable only inside the viewer's organization scope.
            $table->uuid('organization_id')->nullable();
            // A permission name the reader must hold; null: any knowledge reader.
            $table->string('required_permission', 120)->nullable();
            $table->string('language', 5);
            $table->string('status', 15);
            $table->unsignedInteger('version')->default(1);
            $table->string('checksum', 64);
            $table->longText('body');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'classification'], 'intel_knowledge_docs_status_class_idx');
            $table->index('organization_id', 'intel_knowledge_docs_org_idx');
        });

        SchemaResume::create('intelligence_knowledge_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('intelligence_knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('heading', 190)->nullable();
            $table->text('content');
            $table->unsignedInteger('char_count');
            $table->timestamps();
            $table->index(['document_id', 'position'], 'intel_knowledge_chunks_doc_pos_idx');
        });

        // PostgreSQL full-text retrieval (D-8): a stored generated tsvector over heading + content with a GIN
        // index. Other drivers have no equivalent that is safe on every version; they rank with LIKE instead.
        if (DB::getDriverName() === 'pgsql' && ! Schema::hasColumn('intelligence_knowledge_chunks', 'search_vector')) {
            DB::statement("ALTER TABLE intelligence_knowledge_chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(heading, '') || ' ' || content)) STORED");
            DB::statement('CREATE INDEX IF NOT EXISTS intel_knowledge_chunks_search_idx ON intelligence_knowledge_chunks USING GIN (search_vector)');
        }

        SchemaResume::create('intelligence_knowledge_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Redacted before storage (Redactor::text): never an e-mail, phone or secret.
            $table->text('question');
            $table->text('answer')->nullable();
            $table->boolean('grounded')->default(false);
            $table->json('citations')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->string('mode', 15);
            $table->dateTime('created_at');
            $table->index(['user_id', 'created_at'], 'intel_knowledge_questions_user_idx');
        });
    }

    public function down(): void
    {
        foreach (['intelligence_knowledge_questions', 'intelligence_knowledge_chunks', 'intelligence_knowledge_documents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
