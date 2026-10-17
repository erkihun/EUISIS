<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: conversation streams and their members (docs/communication-module.md).
 *
 * One stream model serves direct messages, private groups and channels
 * (type DIRECT | GROUP | CHANNEL), so messages, pins, reactions and read state
 * share one code path. A channel's own metadata lives in `channels`.
 *
 * Read state is a pointer per member (last read / last delivered message id),
 * never a row per message: UUIDv7 ids are time-ordered, so "unread" is
 * `messages.id > last_read_message_id`, served by (conversation_id, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('avatar_path', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            // DIRECT only: "{lower user id}:{higher user id}", so a pair has one conversation.
            $table->string('direct_key', 64)->nullable()->unique();
            $table->uuid('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'last_message_at'], 'conversations_type_recent_idx');
        });

        Schema::create('conversation_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            // MANUAL: added by a person; AUTO: derived from the organizational assignment; SYSTEM: platform-created.
            $table->string('source', 10)->default('MANUAL');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at');
            // A member who left or was removed keeps the row (history), but has no access.
            $table->timestamp('left_at')->nullable();
            $table->uuid('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->uuid('last_delivered_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'conversation_members_unique');
            $table->index(['user_id', 'left_at'], 'conversation_members_user_active_idx');
            $table->index(['conversation_id', 'left_at', 'role'], 'conversation_members_conv_active_idx');
            $table->index(['conversation_id', 'source'], 'conversation_members_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
