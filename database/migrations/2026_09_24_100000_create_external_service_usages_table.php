<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metering for paid external services (SMS today).
 *
 * One row per attempted paid call. Holds no message text and no raw phone
 * number: the recipient is a SHA-256 hash, enough to enforce per-recipient
 * caps and investigate abuse without storing personal data twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_service_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 64);
            $table->string('service', 32);
            $table->string('purpose', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_application_id')->nullable();
            $table->char('recipient_hash', 64)->nullable();
            $table->unsignedInteger('units')->default(1);
            $table->decimal('estimated_cost', 12, 4)->nullable();
            // reserved -> sent | failed; refused rows record a blocked attempt.
            $table->string('status', 16);
            $table->string('refusal_reason', 32)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['service', 'occurred_at'], 'esu_service_time_idx');
            $table->index(['service', 'recipient_hash', 'occurred_at'], 'esu_recipient_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_service_usages');
    }
};
