<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Intelligence — the AI run log (brief §56): one row per material
 * model call with feature, prompt key and version, provider and model, who or
 * what triggered it, the subject it concerned, a summary of what went in
 * (data classes, character count, references — never the raw input), the
 * validated output, timing and the failure reason. No hidden chain of thought.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('intelligence_ai_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('feature', 40);
            $table->string('prompt_key', 60);
            $table->string('prompt_version', 20);
            $table->string('provider', 30);
            $table->string('model', 120)->nullable();
            $table->string('status', 15);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('input_summary');
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('created_at');
            $table->index(['feature', 'created_at'], 'intel_ai_runs_feature_created_idx');
            $table->index(['subject_type', 'subject_id'], 'intel_ai_runs_subject_idx');
            $table->index('created_at', 'intel_ai_runs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_ai_runs');
    }
};
