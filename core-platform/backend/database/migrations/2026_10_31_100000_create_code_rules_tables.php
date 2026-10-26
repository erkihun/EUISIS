<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EUISIS Code Rules tables (code_rules, code_rule_sequences,
 * code_generation_logs), as EUISIS's migrations leave them: the rule, its
 * per-scope sequences and the log of every code handed out. The Core's
 * CodeGenerator uses an active rule when one exists for the entity type and
 * the formats under System Settings otherwise.
 *
 * migration-safety: reviewed (new tables only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('entity_type')->index();
            $table->string('scope_type')->nullable()->index();
            $table->string('scope_id')->nullable()->index();
            $table->string('active_scope_key')->nullable()->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            $table->string('format');
            $table->string('separator', 10)->default('-');
            $table->unsignedSmallInteger('sequence_length')->default(4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->integer('initial_sequence_number')->default(1);
            $table->string('sequence_scope_strategy')->default('auto');
            $table->json('sequence_scope_tokens')->nullable();
            $table->string('reset_frequency')->default('never')->index();
            $table->timestamp('last_reset_at')->nullable();
            $table->string('year_format')->nullable()->default('Y');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('allow_manual_override')->default(false);
            $table->boolean('require_approval_for_override')->default(true);
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['entity_type', 'scope_type', 'scope_id'], 'code_rules_entity_scope_index');
            $table->index(['entity_type', 'is_active'], 'code_rules_entity_active_index');
        });

        Schema::create('code_rule_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('code_rule_id')->constrained('code_rules')->cascadeOnDelete();
            $table->string('sequence_scope_key');
            $table->string('sequence_scope_hash', 64);
            $table->json('sequence_scope_values')->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedBigInteger('last_number')->nullable();
            $table->string('last_generated_code')->nullable();
            $table->string('reset_frequency')->nullable();
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();

            $table->unique(['code_rule_id', 'sequence_scope_hash'], 'code_rule_sequences_rule_scope_unique');
            $table->index('sequence_scope_hash');
            $table->index('updated_at');
        });

        Schema::create('code_generation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('code_rule_id')->constrained('code_rules');
            $table->string('entity_type')->index();
            $table->string('entity_id')->nullable()->index();
            $table->string('generated_code')->index();
            $table->unsignedBigInteger('sequence_number');
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->dateTime('generated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_generation_logs');
        Schema::dropIfExists('code_rule_sequences');
        Schema::dropIfExists('code_rules');
    }
};
