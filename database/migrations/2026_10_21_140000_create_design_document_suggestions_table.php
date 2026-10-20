<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document-assisted capture (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-006): what the assistant suggested from a passage of a source
 * document, kept for a person to apply item by item into draft records or
 * reject. The passage itself is not stored — only its hash and length — so
 * the table holds suggestions, never a copy of the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('design_document_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('mandate_id')->constrained('institution_mandates')->cascadeOnDelete();
            $table->foreignUuid('legal_source_id')->nullable()->constrained('legal_sources')->nullOnDelete();
            $table->string('kind', 30)->default('functions');
            $table->string('status', 20);
            $table->string('passage_sha256', 64);
            $table->unsignedInteger('passage_chars');
            $table->json('items')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mandate_id', 'created_at'], 'design_document_suggestions_mandate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_document_suggestions');
    }
};
