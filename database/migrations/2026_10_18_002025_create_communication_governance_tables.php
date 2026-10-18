<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: tasks made from messages, moderation reports, blocks, per-user
 * settings, and the break-glass access workflow.
 *
 * Moderators never get blanket access to private conversations: a report opens
 * only the reported message and its immediate context, and any wider access is
 * a time-boxed, approved, audited break-glass grant for a named conversation or
 * employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUuid('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // LOW | NORMAL | HIGH | URGENT
            $table->string('priority', 10)->default('NORMAL');
            // TODO | IN_PROGRESS | COMPLETED | CANCELLED
            $table->string('status', 12)->default('TODO');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status', 'due_at'], 'communication_tasks_assignee_idx');
            $table->index(['created_by', 'status'], 'communication_tasks_creator_idx');
        });

        Schema::create('communication_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            // MESSAGE | CONVERSATION
            $table->string('subject_type', 14);
            $table->foreignUuid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->nullOnDelete();
            // SPAM | HARASSMENT | INAPPROPRIATE_CONTENT | SECURITY_CONCERN | OTHER
            $table->string('category', 30);
            $table->text('details')->nullable();
            // OPEN | UNDER_REVIEW | ACTIONED | DISMISSED
            $table->string('status', 14)->default('OPEN');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'communication_reports_queue_idx');
            $table->index(['reporter_id', 'created_at'], 'communication_reports_reporter_idx');
        });

        Schema::create('communication_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['blocker_id', 'blocked_id'], 'communication_blocks_unique');
            $table->index('blocked_id', 'communication_blocks_blocked_idx');
        });

        Schema::create('communication_user_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('allow_direct_messages')->default(true);
            $table->boolean('show_online_status')->default(true);
            $table->boolean('show_last_seen')->default(true);
            $table->boolean('desktop_notifications')->default(true);
            $table->boolean('sound_notifications')->default(true);
            $table->boolean('email_announcements')->default(true);
            $table->boolean('sms_emergency_announcements')->default(true);
            // Presence heartbeat (no presence channel: a city-wide roster would not scale).
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at', 'communication_user_settings_seen_idx');
        });

        Schema::create('communication_access_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('case_reference', 100);
            // CONVERSATION | EMPLOYEE
            $table->string('scope_type', 14);
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            // REQUESTED | APPROVED | REJECTED | ACTIVE | EXPIRED | REVOKED
            $table->string('status', 10)->default('REQUESTED');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'status'], 'communication_access_requester_idx');
            $table->index(['status', 'expires_at'], 'communication_access_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_access_requests');
        Schema::dropIfExists('communication_user_settings');
        Schema::dropIfExists('communication_blocks');
        Schema::dropIfExists('communication_reports');
        Schema::dropIfExists('communication_tasks');
    }
};
