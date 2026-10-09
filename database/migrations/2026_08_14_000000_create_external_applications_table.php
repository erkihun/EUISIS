<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of approved external systems that may call the integration API.
 *
 * The API token itself lives in Sanctum's personal_access_tokens table; this
 * table records who the token belongs to, which scopes they were approved for,
 * and their rate limit. No secret is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('callback_url')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->json('allowed_scopes');
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            // Optional CIDR/IP allowlist; empty means "any source address".
            $table->json('allowed_ips')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_applications');
    }
};
