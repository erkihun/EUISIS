<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: official announcements, separate from chat.
 *
 * Targets are stored once (announcement_targets) and matched per reader from
 * their audience keys, so a city-wide announcement never fans out 300,000 rows
 * at publish. A receipt row is written when a reader is delivered, views or
 * acknowledges; the size of the audience is computed by a queued job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('title_am')->nullable();
            $table->text('body');
            $table->text('body_am')->nullable();
            // NORMAL | IMPORTANT | URGENT | EMERGENCY
            $table->string('priority', 12)->default('NORMAL');
            // DRAFT | SCHEDULED | PUBLISHED | EXPIRED | CANCELLED
            $table->string('status', 12)->default('DRAFT');
            // Summary of the targets for listing; the targets themselves are in announcement_targets.
            $table->string('target_type', 30)->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('acknowledgement_required')->default(false);
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('target_count')->nullable();
            $table->timestamp('target_count_computed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'publish_at'], 'announcements_schedule_idx');
            $table->index(['status', 'expires_at'], 'announcements_expiry_idx');
            $table->index(['status', 'published_at'], 'announcements_feed_idx');
            $table->index(['created_by', 'status'], 'announcements_author_idx');
        });

        Schema::create('announcement_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            // CITY | ORGANIZATION | ORGANIZATION_SUBTREE | ORGANIZATION_UNIT | EMPLOYEE | ROLE | GROUP
            $table->string('target_type', 30);
            // uuid (organization, unit, employee, group) or role id; null for CITY.
            $table->string('target_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['announcement_id', 'target_type', 'target_id'], 'announcement_targets_unique');
            $table->index(['target_type', 'target_id'], 'announcement_targets_lookup_idx');
        });

        Schema::create('announcement_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id'], 'announcement_receipts_unique');
            $table->index(['announcement_id', 'acknowledged_at'], 'announcement_receipts_ack_idx');
            $table->index(['user_id', 'viewed_at'], 'announcement_receipts_user_idx');
        });

        Schema::create('announcement_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_name', 100);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->string('scan_status', 12)->default('SKIPPED');
            $table->timestamps();

            $table->index('announcement_id', 'announcement_attachments_announcement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_attachments');
        Schema::dropIfExists('announcement_receipts');
        Schema::dropIfExists('announcement_targets');
        Schema::dropIfExists('announcements');
    }
};
