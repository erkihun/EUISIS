<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last number given per code pattern (EUISIS code rules, simplified): one
 * row per rendered pattern, e.g. "CARD-2026-{seq}", locked while a number is taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table): void {
            $table->string('pattern', 64)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
