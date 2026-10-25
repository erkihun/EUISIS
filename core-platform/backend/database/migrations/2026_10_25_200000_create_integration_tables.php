<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The integration layer of the Core, for the systems that consume it
 * (Recruitment, EPMS, Cafeteria, Transport, Payroll, …):
 *  - api_clients: one per consuming system, with least-privilege scopes, the
 *    organizations it may read (none = the whole city), allowed addresses, a
 *    rate limit, expiry and revocation. Its secret is a Sanctum token (hashed
 *    at rest, shown once); the integer key is what Sanctum's token table
 *    references, the UUID is the public reference.
 *  - api_request_logs: one row per integration call (no payloads).
 *  - outbox_events: every published domain change, written in the same
 *    transaction as the change, so an event exists exactly when the change does.
 *  - webhook_endpoints / webhook_deliveries: signed delivery of those events,
 *    retried with back-off outside the business transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->uuid('owner_organization_id')->nullable();
            $table->json('scopes');
            $table->json('allowed_ips')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(120);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('owner_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['is_active', 'revoked_at'], 'api_clients_active_idx');
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->restrictOnDelete();
            $table->string('method', 8);
            $table->string('route', 160);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('denial_code', 40)->nullable();
            $table->dateTime('created_at');
            $table->index(['api_client_id', 'created_at'], 'api_request_logs_client_created_idx');
            $table->index(['status', 'created_at'], 'api_request_logs_status_created_idx');
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 64);
            $table->string('entity_type', 64);
            $table->string('entity_id', 64);
            $table->uuid('organization_id')->nullable();
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->json('data');
            $table->string('request_id', 64)->nullable();
            $table->dateTime('occurred_at');
            $table->index(['occurred_at'], 'outbox_events_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'outbox_events_type_occurred_idx');
            $table->index(['entity_type', 'entity_id'], 'outbox_events_entity_idx');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('url', 500);
            $table->text('secret');
            $table->json('event_types');
            $table->uuid('scope_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('scope_organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('webhook_endpoint_id');
            $table->uuid('outbox_event_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error', 1000)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_endpoint_id', 'outbox_event_id'], 'webhook_deliveries_endpoint_event_unique');
            $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_due_idx');
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->restrictOnDelete();
            $table->foreign('outbox_event_id')->references('id')->on('outbox_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
    }
};
