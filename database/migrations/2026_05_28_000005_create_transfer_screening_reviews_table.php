<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_screening_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('transfer_application_id')->constrained('transfer_applications')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users');

            // Action taken: under_review, verified, selected, rejected, etc.
            $table->string('action', 40);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['transfer_application_id', 'action']);
            $table->index('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_screening_reviews');
    }
};
