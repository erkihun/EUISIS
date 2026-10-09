<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization review policy for daily activity. An organization with no
 * row follows the city-wide "Manager Review Required" setting, so this is a
 * purely additive table: nothing existing changes until a row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity_review_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('review_mode', 20)->default('inherit');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity_review_policies');
    }
};
