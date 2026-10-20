<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event integration layer (docs/integration/transactional-outbox.md).
 *
 * integration_events          the transactional outbox AND the change feed: a
 *                             row is written in the same transaction as the
 *                             domain change; the publisher later stamps a
 *                             gap-free feed_position in commit-visibility order.
 * integration_aggregate_versions  per-aggregate sequence counter.
 * integration_feed_state      single row: the last feed_position handed out.
 * integration_feed_consumers  last position each application read (reconciliation report).
 * webhook_subscriptions       a partner application's push endpoint.
 * webhook_deliveries          one per (subscription, event, generation); replays add a generation.
 * webhook_delivery_attempts   one per HTTP attempt — metadata only, never bodies.
 * integration_replays         who replayed what, why, and the result.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique('integration_events_event_id_unique');
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('aggregate_type', 40);
            $table->string('aggregate_uuid', 36);
            $table->unsignedInteger('sequence');
            // Authorization columns are relational, never only inside the payload.
            $table->uuid('organization_id')->nullable();
            $table->uuid('related_organization_id')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('change_kind', 16)->default('upsert');
            $table->dateTime('occurred_at', 6);
            $table->date('effective_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->uuid('causation_id')->nullable();
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('available_at', 6);
            $table->timestamp('published_at', 6)->nullable();
            $table->unsignedBigInteger('feed_position')->nullable()->unique('integration_events_feed_position_unique');
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->unique(['aggregate_type', 'aggregate_uuid', 'sequence'], 'integration_events_aggregate_sequence_unique');
            $table->index(['status', 'available_at', 'id'], 'integration_events_pending_idx');
            $table->index(['organization_id', 'feed_position'], 'integration_events_org_feed_idx');
            $table->index(['related_organization_id', 'feed_position'], 'integration_events_related_org_feed_idx');
            $table->index(['event_type', 'feed_position'], 'integration_events_type_feed_idx');
            $table->index('occurred_at', 'integration_events_occurred_idx');
        });

        SchemaResume::create('integration_aggregate_versions', function (Blueprint $table): void {
            $table->string('aggregate_type', 40);
            $table->string('aggregate_uuid', 36);
            $table->unsignedInteger('version');
            $table->timestamp('updated_at', 6)->nullable();

            $table->primary(['aggregate_type', 'aggregate_uuid'], 'integration_aggregate_versions_pk');
        });

        SchemaResume::create('integration_feed_state', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedBigInteger('last_position')->default(0);
            $table->timestamp('updated_at', 6)->nullable();
        });
        if (DB::table('integration_feed_state')->where('id', 1)->doesntExist()) {
            DB::table('integration_feed_state')->insert(['id' => 1, 'last_position' => 0, 'updated_at' => now()]);
        }

        SchemaResume::create('integration_feed_consumers', function (Blueprint $table): void {
            $table->string('external_application_id', 36)->primary();
            $table->foreign('external_application_id')->references('id')->on('external_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('last_position')->default(0);
            $table->unsignedInteger('last_batch_size')->default(0);
            $table->timestamp('last_read_at', 6)->nullable();
        });

        SchemaResume::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_application_id', 36);
            $table->foreign('external_application_id')->references('id')->on('external_applications')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('callback_url', 2048);
            $table->json('event_types');
            $table->string('status', 16)->default('active');
            // Encrypted at rest (cast), never shown after creation/rotation.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            // Events at or before this feed position are not delivered.
            $table->unsignedBigInteger('start_after_position')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_category', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status'], 'webhook_subscriptions_status_idx');
            $table->index(['external_application_id', 'status'], 'webhook_subscriptions_app_idx');
        });

        SchemaResume::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('integration_event_id');
            $table->foreign('integration_event_id')->references('id')->on('integration_events')->restrictOnDelete();
            $table->uuid('event_id');
            $table->unsignedBigInteger('feed_position');
            $table->string('aggregate_key', 80);
            $table->unsignedSmallInteger('generation')->default(0);
            $table->uuid('replay_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('locked_until', 6)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_category', 40)->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->timestamp('first_attempt_at', 6)->nullable();
            $table->timestamp('last_attempt_at', 6)->nullable();
            $table->timestamp('delivered_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['webhook_subscription_id', 'event_id', 'generation'], 'webhook_deliveries_identity_unique');
            $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_due_idx');
            $table->index(['webhook_subscription_id', 'status', 'feed_position'], 'webhook_deliveries_subscription_idx');
            $table->index(['webhook_subscription_id', 'aggregate_key', 'feed_position'], 'webhook_deliveries_order_idx');
            $table->index('event_id', 'webhook_deliveries_event_idx');
        });

        SchemaResume::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('webhook_delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_category', 40)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->dateTime('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->unique(['webhook_delivery_id', 'attempt'], 'webhook_delivery_attempts_unique');
        });

        SchemaResume::create('integration_replays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('initiated_by_application_id', 36)->nullable();
            $table->json('criteria');
            $table->string('reason', 500);
            $table->unsignedInteger('event_count')->default(0);
            $table->string('status', 16)->default('queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['integration_replays', 'webhook_delivery_attempts', 'webhook_deliveries', 'webhook_subscriptions', 'integration_feed_consumers', 'integration_feed_state', 'integration_aggregate_versions', 'integration_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
