<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_card_id')->constrained('id_cards')->restrictOnDelete();
            $table->string('credential_id', 68)->unique();
            $table->string('chip_uid_hash', 64)->nullable();
            $table->string('credential_type', 32);
            $table->string('key_version', 64)->nullable();
            $table->string('key_reference')->nullable();
            $table->string('status', 24)->index();
            $table->timestamp('issued_at');
            foreach (['activated_at', 'expires_at', 'revoked_at', 'last_used_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->foreignUuid('replaced_by_id')->nullable()->constrained('nfc_credentials')->restrictOnDelete();
            // users.id is a BIGINT, not a UUID: these must match it or MySQL
            // rejects the constraint (errno 150).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['id_card_id', 'status']);
        });
        Schema::create('service_terminals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()->constrained('service_providers')->restrictOnDelete();
            $table->foreignUuid('cafeteria_provider_id')->nullable()->constrained('cafeteria_providers')->restrictOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('external_application_id')->nullable()->constrained('external_applications')->restrictOnDelete();
            $table->string('terminal_code', 64)->unique();
            $table->string('name');
            $table->string('terminal_type', 24);
            $table->string('service_type', 64)->nullable();
            $table->string('status', 24)->index();
            $table->string('certificate_reference')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('nfc_challenges', function (Blueprint $table): void {
            $table->string('nonce_hash', 64)->primary();
            $table->foreignUuid('nfc_credential_id')->constrained('nfc_credentials')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('service_terminals')->restrictOnDelete();
            $table->string('context_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
        Schema::create('nfc_verification_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('nfc_credential_id')->nullable()->constrained('nfc_credentials')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('service_terminals')->restrictOnDelete();
            $table->foreignUuid('external_application_id')->nullable()->constrained('external_applications')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('result', 24);
            $table->string('reason_code', 64)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->index(['nfc_credential_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_verification_logs');
        Schema::dropIfExists('nfc_challenges');
        Schema::dropIfExists('service_terminals');
        Schema::dropIfExists('nfc_credentials');
    }
};
