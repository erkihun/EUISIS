<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Organization Baseline (docs/organization-design-legacy-baseline-plan.md,
 * ADR-LB-002/003): what an institution legally and administratively was
 * before Addis One, captured as a workflow container over the design
 * records that already exist, with a frozen snapshot on publication.
 *
 *   legacy_baselines          — versioned by lineage_key + version_no; the
 *                               workflow status is the module's shared enum,
 *                               origin / validation_status carry the rest
 *   legacy_baseline_steps     — the wizard's 22 steps, status kept server-side
 *   legacy_baseline_snapshots — the JSON frozen when a baseline is published:
 *                               units, relationships, positions,
 *                               establishments and the chain's record ids
 *
 * Additive and resumable; nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('legacy_baselines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('reference_number', 30)->unique();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description')->nullable();
            $table->string('origin', 20)->default('manual');
            $table->date('effective_date')->nullable();
            $table->foreignUuid('hierarchy_version_id')->nullable()->constrained('hierarchy_versions')->nullOnDelete();
            $table->foreignUuid('legal_source_id')->nullable()->constrained('legal_sources')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('validation_status', 20)->default('not_run');
            $table->json('validation_summary')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedInteger('mapping_required_count')->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'legacy_baselines_lineage_version_unique');
            $table->index(['organization_id', 'status'], 'legacy_baselines_org_status_idx');
        });

        SchemaResume::create('legacy_baseline_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('baseline_id')->constrained('legacy_baselines')->cascadeOnDelete();
            $table->string('step_key', 40);
            $table->string('status', 20)->default('incomplete');
            $table->string('status_source', 10)->default('auto');
            $table->unsignedInteger('record_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['baseline_id', 'step_key'], 'legacy_baseline_steps_unique');
        });

        SchemaResume::create('legacy_baseline_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('baseline_id')->unique()->constrained('legacy_baselines')->cascadeOnDelete();
            $table->foreignUuid('hierarchy_version_id')->nullable()->constrained('hierarchy_versions')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->json('counts');
            $table->json('units');
            $table->json('relationships');
            $table->json('positions');
            $table->json('establishments');
            $table->json('chain');
            $table->string('sha256', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_baseline_snapshots');
        Schema::dropIfExists('legacy_baseline_steps');
        Schema::dropIfExists('legacy_baselines');
    }
};
