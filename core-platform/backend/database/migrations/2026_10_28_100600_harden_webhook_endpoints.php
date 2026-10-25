<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 (ADR 0010): webhook reliability.
 *
 *  - consecutive_failures counts failed attempts since the last success; at
 *    integration.webhook_suspend_after the endpoint is suspended (is_active
 *    off, with when and why) until an administrator resumes it. Its pending
 *    deliveries wait; nothing is dropped.
 *  - A rotated signing secret stays valid for integration.webhook_secret_grace_hours:
 *    deliveries carry both signatures until then, so the receiver can switch over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn(['consecutive_failures', 'suspended_at', 'suspended_reason', 'previous_secret', 'previous_secret_expires_at']);
        });
    }
};
