<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Intelligence — technical domain storage (docs/intelligence-system-health.md).
 *
 * signals             time-bucketed metric samples the collector writes (never a log line)
 * error_fingerprints  one row per repeated error signature, counted, never a stack trace with secrets
 * incidents           the correlated technical incident, with its timeline in incident_events
 * recommendations     persisted recommendations with their review, never overwritten
 * deployments         release metadata recorded by `intelligence:record-deployment`
 *
 * Resumable (SchemaResume), MySQL-safe: dateTime for mandatory moments, named
 * indexes, user foreign keys as foreignId (users have bigint ids).
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('intelligence_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('metric', 80);
            $table->string('component', 60);
            $table->string('dimension', 120)->nullable();
            $table->decimal('value', 18, 4);
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('sample_count')->default(1);
            $table->dateTime('bucket_start');
            $table->dateTime('created_at');
            $table->index(['metric', 'dimension', 'bucket_start'], 'intel_signals_metric_dim_bucket_idx');
            $table->index(['component', 'bucket_start'], 'intel_signals_component_bucket_idx');
            $table->index('bucket_start', 'intel_signals_bucket_idx');
        });

        SchemaResume::create('intelligence_error_fingerprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('fingerprint', 40)->unique('intel_fingerprints_fp_unique');
            $table->string('exception_class', 190);
            $table->string('message_normalized', 500);
            $table->string('route_name', 190)->nullable();
            $table->string('route_group', 40);
            $table->string('component', 60)->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error_id', 24)->nullable();
            $table->json('sample_context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->uuid('incident_id')->nullable();
            $table->timestamps();
            $table->index(['last_seen_at'], 'intel_fingerprints_last_seen_idx');
            $table->index(['route_group', 'last_seen_at'], 'intel_fingerprints_group_seen_idx');
        });

        SchemaResume::create('intelligence_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 24)->unique('intel_incidents_reference_unique');
            $table->string('title', 190);
            $table->string('severity', 10);
            $table->string('status', 15);
            $table->string('component', 60);
            $table->string('module', 60)->nullable();
            $table->string('endpoint', 190)->nullable();
            $table->char('fingerprint', 40);
            $table->string('rule', 60);
            $table->dateTime('detected_at');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('mitigated_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            // Consecutive detection runs without the condition; CLEAR_RUNS of them mitigate the incident.
            $table->unsignedSmallInteger('clear_runs')->default(0);
            $table->dateTime('last_notified_at')->nullable();
            $table->text('impact_summary')->nullable();
            $table->json('evidence');
            $table->json('source_signals')->nullable();
            $table->text('probable_cause')->nullable();
            $table->string('cause_confidence', 10)->nullable();
            $table->json('recommendation')->nullable();
            $table->uuid('deployment_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_summary')->nullable();
            $table->string('verification_status', 15)->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity'], 'intel_incidents_status_severity_idx');
            $table->index(['fingerprint', 'status'], 'intel_incidents_fp_status_idx');
            $table->index(['component', 'last_seen_at'], 'intel_incidents_component_seen_idx');
            $table->index('detected_at', 'intel_incidents_detected_idx');
        });

        SchemaResume::create('intelligence_incident_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('intelligence_incidents')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_value', 40)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->index(['incident_id', 'occurred_at'], 'intel_incident_events_incident_idx');
        });

        SchemaResume::create('intelligence_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type', 30);
            $table->string('source_id', 60)->nullable();
            $table->uuid('incident_id')->nullable();
            $table->string('area', 40)->nullable();
            $table->string('category', 20);
            $table->string('risk', 15);
            $table->string('title', 190);
            $table->text('body');
            $table->json('evidence')->nullable();
            $table->string('confidence', 10)->nullable();
            $table->string('generated_by', 20);
            $table->string('prompt_version', 40)->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->dateTime('generated_at');
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('linked_action_reference', 60)->nullable();
            $table->timestamps();
            $table->index(['review_status', 'generated_at'], 'intel_recs_review_generated_idx');
            $table->index(['incident_id'], 'intel_recs_incident_idx');
            $table->index(['source_type', 'source_id'], 'intel_recs_source_idx');
        });

        SchemaResume::create('intelligence_deployments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('release', 80);
            $table->string('commit_hash', 64)->nullable();
            $table->string('environment', 30);
            $table->dateTime('deployed_at');
            $table->string('change_reference', 120)->nullable();
            $table->string('recorded_by', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('deployed_at', 'intel_deployments_deployed_idx');
        });
    }

    public function down(): void
    {
        foreach (['intelligence_incident_events', 'intelligence_recommendations', 'intelligence_incidents', 'intelligence_error_fingerprints', 'intelligence_signals', 'intelligence_deployments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
