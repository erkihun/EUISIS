<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->timestamps();

            $table->index(['entity_type', 'scope_type', 'scope_id'], 'code_rules_entity_scope_index');
            $table->index(['entity_type', 'is_active'], 'code_rules_entity_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_rules');
    }
};
