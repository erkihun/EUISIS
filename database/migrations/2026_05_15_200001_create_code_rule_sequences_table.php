<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->index('code_rule_id');
            $table->index('sequence_scope_hash');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_rule_sequences');
    }
};
