<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: messages and what hangs off them — attachments, reactions,
 * parsed mentions and pins.
 *
 * Messages are soft-deleted (retention); a deleted message keeps its row but
 * its body is never returned to members. Attachments are uploaded before the
 * message is sent, so they belong to a conversation first and to a message once
 * it is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('parent_message_id')->nullable();
            $table->uuid('forwarded_from_message_id')->nullable();
            $table->string('type', 20)->default('TEXT');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            // Idempotency: a retried send with the same client id is not stored twice.
            $table->string('client_message_id', 64)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'id'], 'messages_conversation_cursor_idx');
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_idx');
            $table->index(['sender_id', 'created_at'], 'messages_sender_created_idx');
            $table->index('parent_message_id', 'messages_parent_idx');
            $table->unique(['sender_id', 'client_message_id'], 'messages_client_id_unique');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreign('parent_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('forwarded_from_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('message_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_name', 100);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            // PENDING (scan not run yet) | CLEAN | INFECTED | SKIPPED (scanning disabled).
            $table->string('scan_status', 12)->default('SKIPPED');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['message_id'], 'message_attachments_message_idx');
            $table->index(['conversation_id', 'created_at'], 'message_attachments_shared_idx');
            $table->index(['uploaded_by', 'message_id'], 'message_attachments_pending_idx');
        });

        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id', 'emoji'], 'message_reactions_unique');
        });

        Schema::create('message_mentions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // USER | CHANNEL | EVERYONE
            $table->string('mention_type', 10);
            $table->timestamp('created_at')->nullable();

            $table->index(['mentioned_user_id', 'created_at'], 'message_mentions_user_idx');
            $table->index(['conversation_id', 'mention_type', 'message_id'], 'message_mentions_conv_idx');
        });

        Schema::create('conversation_pins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pinned_at');

            $table->unique(['conversation_id', 'message_id'], 'conversation_pins_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_pins');
        Schema::dropIfExists('message_mentions');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
    }
};
