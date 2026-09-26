<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_password_reset_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_user_id')->constrained('provider_users')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider_user_id', 'used_at', 'expires_at'], 'provider_password_reset_code_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_password_reset_codes');
    }
};
