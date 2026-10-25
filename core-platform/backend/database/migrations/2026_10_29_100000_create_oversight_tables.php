<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4/P5 (ADR 0011): oversight records.
 *
 *  - data_quality_issues: one row per finding of a check (check, entity),
 *    with a fixed severity per check and a lifecycle: open → investigating →
 *    resolved, accepted risk or false positive. No record snapshot is kept,
 *    only identifiers and the check's own short summary.
 *  - core_notifications: a person's in-app notifications (an event key and
 *    its parameters, written in the reader's language when shown), and
 *    notification_deliveries for e-mail and SMS attempts.
 *  - export_runs: every report export — who, when, filters, columns, row
 *    count, format, file and each download.
 *  - The P4/P5 permissions for the system roles that existed before them.
 */
return new class extends Migration
{
    private const GRANTS = [
        'core_viewer' => ['data_quality.view', 'reports.view'],
        'core_officer' => ['data_quality.view', 'reports.view', 'data_quality.manage', 'reports.export'],
        'core_admin' => ['data_quality.view', 'reports.view', 'data_quality.manage', 'reports.export',
            'data_quality.accept_risk', 'reports.export_sensitive', 'system_health.view', 'queue.manage', 'security_dashboard.view'],
    ];

    public function up(): void
    {
        Schema::create('data_quality_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('check_code', 64);
            $table->string('entity_type', 32);
            $table->string('entity_id', 64);
            $table->uuid('organization_id')->nullable();
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('times_reopened')->default(0);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('auto_resolved')->default(false);
            $table->unsignedBigInteger('accepted_risk_by')->nullable();
            $table->timestamp('accepted_risk_at')->nullable();
            $table->timestamps();
            $table->unique(['check_code', 'entity_type', 'entity_id'], 'data_quality_issues_finding_unique');
            $table->index(['status', 'severity'], 'data_quality_issues_status_severity_idx');
            $table->index(['organization_id', 'status'], 'data_quality_issues_organization_idx');
            $table->index(['assigned_to', 'status'], 'data_quality_issues_assignee_idx');
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('accepted_risk_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('core_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('event', 64);
            $table->json('params')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'read_at', 'created_at'], 'core_notifications_user_idx');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('core_notification_id');
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->timestamp('attempted_at');
            $table->index(['core_notification_id'], 'notification_deliveries_notification_idx');
            $table->index(['channel', 'status', 'attempted_at'], 'notification_deliveries_status_idx');
            $table->foreign('core_notification_id')->references('id')->on('core_notifications')->cascadeOnDelete();
        });

        Schema::create('export_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('report', 64);
            $table->string('format', 8);
            $table->string('language', 4);
            $table->json('filters');
            $table->json('columns');
            $table->boolean('include_sensitive')->default(false);
            $table->string('purpose', 500)->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedBigInteger('last_downloaded_by')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'created_at'], 'export_runs_requester_idx');
            $table->index(['status'], 'export_runs_status_idx');
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('last_downloaded_by')->references('id')->on('users')->nullOnDelete();
        });

        foreach (self::GRANTS as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            if ($id !== null) {
                DB::table('core_role_permissions')->insertOrIgnore(array_map(fn (string $p): array => ['core_role_id' => $id, 'permission' => $p], $permissions));
            }
        }
        cache()->forget('core.roles');
    }

    public function down(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            DB::table('core_role_permissions')->where('core_role_id', $id)->whereIn('permission', $permissions)->delete();
        }
        cache()->forget('core.roles');
        Schema::dropIfExists('export_runs');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('core_notifications');
        Schema::dropIfExists('data_quality_issues');
    }
};
