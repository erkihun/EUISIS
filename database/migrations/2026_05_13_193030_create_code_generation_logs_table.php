<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
